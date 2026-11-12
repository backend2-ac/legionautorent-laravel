<?php

namespace App\Console\Commands;

use App\Models\Car;
use App\Models\CarCategory;
use App\Models\CarDiscount;
use App\Models\CarImage;
use App\Models\City;
use App\Services\PublicContent;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Brings the fleet in line with the old public site: which cars each city shows, daily prices,
 * discount tiers, photos of cars that are new there, and the old site's order (most expensive first).
 * Safe to run again before the domain switch; --dry-run only prints what would change.
 */
class SyncLegacyFleet extends Command
{
    protected $signature = 'legion:sync-legacy-fleet {--source=https://legionautorent.kz : Old site address} {--dry-run : Only report the differences}';

    protected $description = 'Sync cars, cities, prices, discounts, photos and order from the old site';

    public function handle(): int
    {
        $source = rtrim((string) $this->option('source'), '/');
        $dryRun = (bool) $this->option('dry-run');

        try {
            $lists = $this->cityLists($source);
        } catch (RuntimeException|ConnectionException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $order = $this->mergedOrder($lists);
        $cityIds = City::whereIn('slug', array_keys($lists))->pluck('id', 'slug');
        $rows = [];

        foreach ($order as $position => $slug) {
            $listed = collect($lists)->map(fn (array $cars): ?array => $cars[$slug] ?? null)->filter();
            $card = $listed->first();
            $details = $this->carDetails($source, $slug);
            $car = Car::where('legacy_path', '/car/'.$slug)->with(['cities', 'discounts', 'images'])->first();
            $changes = [];

            if (! $car) {
                $changes[] = 'новая машина, фото: '.count($details['images']);
            } else {
                if ((int) $car->base_price !== $card['price']) {
                    $changes[] = 'цена '.(int) $car->base_price.' → '.$card['price'];
                }
                if (! $car->active) {
                    $changes[] = 'включена';
                }
                $currentCities = $car->cities->pluck('slug')->sort()->values()->all();
                if ($currentCities !== $listed->keys()->sort()->values()->all()) {
                    $changes[] = 'города '.implode(',', $currentCities).' → '.$listed->keys()->sort()->implode(',');
                }
                if ($details['discounts'] && $this->discountKey($car->discounts->map->only(['min_days', 'max_days', 'percent'])->all()) !== $this->discountKey($details['discounts'])) {
                    $changes[] = 'скидки обновлены';
                }
                $missingPhotos = array_diff($details['images'], $car->images->pluck('legacy_url')->all());
                if ($missingPhotos) {
                    $changes[] = 'не хватает фото: '.count($missingPhotos);
                }
            }
            if ($changes) {
                $rows[] = [$position + 1, $card['name'], $listed->keys()->implode(', '), $card['price'], implode('; ', $changes)];
            }
            if ($dryRun) {
                continue;
            }

            DB::transaction(function () use ($car, $card, $details, $slug, $position, $listed, $cityIds, $source): void {
                $car ??= $this->createCar($slug, $card);
                $car->forceFill(['base_price' => $card['price'], 'active' => true, 'sort_order' => $position])->save();
                $car->cities()->sync($listed->keys()->map(fn (string $city): int => $cityIds[$city])->all());
                if ($details['discounts']) {
                    $car->discounts()->delete();
                    foreach ($details['discounts'] as $discount) {
                        CarDiscount::create(['car_id' => $car->id, 'label' => $discount['label'], 'min_days' => $discount['min_days'], 'max_days' => $discount['max_days'], 'percent' => $discount['percent']]);
                    }
                }
                $this->addMissingPhotos($car, $details['images'], $source, $card['name']);
            });
        }

        $absent = Car::where('active', true)->whereNotIn('legacy_path', array_map(fn (string $slug): string => '/car/'.$slug, $order))->pluck('name')->all();
        PublicContent::forget();

        $this->table(['#', 'Автомобиль', 'Города', 'Цена', 'Изменения'], $rows);
        $this->info(($dryRun ? 'Проверка без записи. ' : '').'На старом сайте машин: '.count($order).', изменений: '.count($rows).'.');
        if ($absent) {
            $this->warn('Активны здесь, но нет на старом сайте: '.implode(', ', $absent));
        }

        return self::SUCCESS;
    }

    /**
     * Cars of every active city in the old site's order.
     *
     * @return array<string, array<string, array{name: string, price: int, position: int}>>
     */
    private function cityLists(string $source): array
    {
        $lists = [];
        foreach (City::where('active', true)->orderBy('sort_order')->get() as $city) {
            $response = $this->fetch($source.$city->legacy_path);
            if (! $response?->successful()) {
                throw new RuntimeException('Старый сайт не ответил для '.$city->name.' ('.($response?->status() ?? 'нет связи').'). Ничего не изменено.');
            }
            $cars = [];
            foreach (array_slice(explode('class="car_item', $response->body()), 1) as $position => $item) {
                if (preg_match('#href="/car/([^"/?]+)"#', $item, $slug) && preg_match('#class="car_name">([^<]+)<#u', $item, $name) && preg_match('#<span>([\d\s]+)#u', $item, $price)) {
                    $cars[$slug[1]] = ['name' => html_entity_decode(trim($name[1])), 'price' => (int) preg_replace('/\D/', '', $price[1]), 'position' => $position];
                }
            }
            if (! $cars) {
                throw new RuntimeException('На странице города '.$city->name.' не найдено ни одной машины. Ничего не изменено.');
            }
            $lists[$city->slug] = $cars;
        }

        return $lists;
    }

    /**
     * One fleet-wide order that keeps every city's order: higher price first, the old site's tie order otherwise.
     *
     * @param  array<string, array<string, array{name: string, price: int, position: int}>>  $lists
     * @return list<string>
     */
    private function mergedOrder(array $lists): array
    {
        $after = [];
        $waiting = [];
        $price = [];
        $firstSeen = [];
        foreach ($lists as $cars) {
            $previous = null;
            foreach ($cars as $slug => $car) {
                $price[$slug] = $car['price'];
                $firstSeen[$slug] = min($firstSeen[$slug] ?? PHP_INT_MAX, $car['position']);
                $waiting[$slug] ??= 0;
                if ($previous !== null && ! in_array($slug, $after[$previous] ?? [], true)) {
                    $after[$previous][] = $slug;
                    $waiting[$slug]++;
                }
                $previous = $slug;
            }
        }
        $order = [];
        $ready = array_keys(array_filter($waiting, fn (int $count): bool => $count === 0));
        $rank = fn (string $a, string $b): int => [$price[$b], $firstSeen[$a]] <=> [$price[$a], $firstSeen[$b]];
        while (count($order) < count($waiting)) {
            if (! $ready) {
                // Cities list some equally priced cars in opposite orders; release the best remaining one.
                $left = array_values(array_diff(array_keys($waiting), $order));
                usort($left, $rank);
                $ready = [$left[0]];
                $waiting[$left[0]] = 0;
            }
            usort($ready, $rank);
            $slug = array_shift($ready);
            $order[] = $slug;
            foreach ($after[$slug] ?? [] as $next) {
                if ($waiting[$next] > 0 && --$waiting[$next] === 0 && ! in_array($next, $order, true)) {
                    $ready[] = $next;
                }
            }
        }

        return $order;
    }

    /**
     * @return array{images: list<string>, discounts: list<array{label: string, min_days: int, max_days: ?int, percent: int}>}
     */
    private function carDetails(string $source, string $slug): array
    {
        $response = $this->fetch($source.'/car/'.$slug);
        if (! $response?->successful()) {
            return ['images' => [], 'discounts' => []];
        }
        $html = $response->body();
        preg_match_all('#class="car_inner_img"[^>]*data-src="(/img/[^"]+)"#', $html, $images);
        preg_match_all('#term_duration">\s*(\d+)\s*-\s*(\d+)[^<]*</div>\s*<div class="term_discount">[^<\d]*(\d+)\s*%#u', $html, $terms, PREG_SET_ORDER);

        return [
            'images' => array_values(array_unique(array_map(fn (string $path): string => $source.$path, $images[1]))),
            'discounts' => array_map(fn (array $term): array => ['label' => $term[1].'-'.$term[2].' дней', 'min_days' => (int) $term[1], 'max_days' => (int) $term[2], 'percent' => (int) $term[3]], $terms),
        ];
    }

    /**
     * @param  array{name: string, price: int, position: int}  $card
     */
    private function createCar(string $slug, array $card): Car
    {
        $family = explode('_', $slug)[0];
        $sibling = Car::where('slug', 'like', $family.'%')->whereNotNull('category_id')->orderBy('id')->first();
        $car = new Car(['name' => $card['name'], 'slug' => $slug, 'legacy_path' => '/car/'.$slug, 'legacy_id' => 'http:/car/'.$slug, 'base_price' => $card['price'], 'seo_title' => $card['name'], 'seo_h1' => 'Аренда '.$card['name'], 'seo_description' => '', 'canonical_url' => rtrim((string) config('legion.site_url'), '/').'/car/'.$slug, 'robots' => 'index,follow', 'active' => true, 'accepts_requests' => true, 'description' => '']);
        $car->category_id = $sibling?->category_id;
        $car->save();
        if (! $car->category_id && $car->brand_id) {
            $car->category_id = Car::where('brand_id', $car->brand_id)->whereNotNull('category_id')->groupBy('category_id')->orderByRaw('count(*) desc')->value('category_id');
        }
        $car->category_id ??= CarCategory::where('slug', 'comfort')->value('id');
        $car->model_name = trim(preg_replace('/^'.preg_quote((string) $car->brand?->name, '/').'\s*/iu', '', $car->name));
        $car->save();

        return $car;
    }

    /**
     * @param  list<string>  $urls
     */
    private function addMissingPhotos(Car $car, array $urls, string $source, string $name): void
    {
        $known = $car->images()->pluck('legacy_url')->all();
        $hasMain = $car->images()->where('is_main', true)->exists();
        $next = (int) $car->images()->max('sort_order') + ($known ? 1 : 0);
        foreach ($urls as $url) {
            if (in_array($url, $known, true) || ! str_starts_with($url, $source.'/img/')) {
                continue;
            }
            $response = $this->fetch($url);
            $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][strtok((string) $response?->header('Content-Type'), ';')] ?? null;
            if (! $response?->successful() || ! $extension || ! @imagecreatefromstring($response->body())) {
                $this->warn('Фото не скачалось: '.$url);

                continue;
            }
            $path = 'cars/originals/'.bin2hex(random_bytes(12)).'.'.$extension;
            Storage::disk('media')->put($path, $response->body());
            CarImage::create(['car_id' => $car->id, 'original' => $path, 'legacy_url' => $url, 'alt' => $name, 'caption' => '', 'is_main' => ! $hasMain, 'sort_order' => $next++]);
            $hasMain = true;
        }
    }

    private function fetch(string $url): ?Response
    {
        try {
            return Http::timeout(60)->retry(2, 1000, throw: false)->withUserAgent('LegionFleetSync/1.0')->get($url);
        } catch (ConnectionException $e) {
            $this->warn($e->getMessage());

            return null;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $discounts
     */
    private function discountKey(array $discounts): string
    {
        return collect($discounts)->map(fn (array $d): string => $d['min_days'].'-'.$d['max_days'].':'.$d['percent'])->sort()->implode('|');
    }
}
