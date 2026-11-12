<?php

namespace Tests\Feature;

use App\Models\Car;
use App\Models\City;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\CatalogueTestCase;

class SyncLegacyFleetTest extends CatalogueTestCase
{
    private const SOURCE = 'https://old.test';

    public function test_it_copies_cars_prices_discounts_photos_and_order_from_the_old_site(): void
    {
        [$expensive, $cheap] = Car::public()->orderBy('id')->take(2)->get()->all();
        $this->fakeOldSite([
            'astana' => [['chery-tiggo-7-pro', 'Chery Tiggo 7 PRO', 990000], [$this->slug($cheap), $cheap->name, 980000]],
            'other' => [[$this->slug($expensive), $expensive->name, 15000]],
        ]);

        $this->artisan('legion:sync-legacy-fleet', ['--source' => self::SOURCE])->assertSuccessful();

        $new = Car::where('legacy_path', '/car/chery-tiggo-7-pro')->with(['cities', 'discounts', 'images', 'brand'])->firstOrFail();
        $this->assertSame(990000, (int) $new->base_price);
        $this->assertSame(['astana'], $new->cities->pluck('slug')->all());
        $this->assertSame('Chery', $new->brand?->name);
        $this->assertSame([10, 30], $new->discounts->pluck('percent')->sort()->values()->all());
        $this->assertCount(2, $new->images);
        $this->assertNotEmpty($new->images->first()->card_image);
        $this->assertSame(self::SOURCE.'/img/cars/main.jpg', $new->images->firstWhere('is_main', true)->legacy_url);

        $this->assertSame(980000, (int) $cheap->fresh()->base_price);
        $this->assertSame(City::where('active', true)->where('slug', '!=', 'astana')->pluck('slug')->sort()->values()->all(), $expensive->fresh()->cities->pluck('slug')->sort()->values()->all());

        $page = $this->get('/cars/astana/')->assertOk()->getContent();
        $this->assertLessThan(strpos($page, e($cheap->name)), strpos($page, 'Chery Tiggo 7 PRO'));
    }

    public function test_nothing_changes_when_a_city_page_is_unavailable(): void
    {
        $before = Car::count();
        Http::fake([self::SOURCE.'/*' => Http::response('', 503)]);

        $this->artisan('legion:sync-legacy-fleet', ['--source' => self::SOURCE])->assertFailed();

        $this->assertSame($before, Car::count());
    }

    public function test_dry_run_only_reports(): void
    {
        $car = Car::public()->first();
        $this->fakeOldSite(['astana' => [[$this->slug($car), $car->name, 777000]], 'other' => [[$this->slug($car), $car->name, 777000]]]);

        $this->artisan('legion:sync-legacy-fleet', ['--source' => self::SOURCE, '--dry-run' => true])->assertSuccessful();

        $this->assertNotSame(777000, (int) $car->fresh()->base_price);
    }

    private function slug(Car $car): string
    {
        return substr($car->legacy_path, strlen('/car/'));
    }

    /**
     * @param  array{astana: list<array{0: string, 1: string, 2: int}>, other: list<array{0: string, 1: string, 2: int}>}  $cities
     */
    private function fakeOldSite(array $cities): void
    {
        $cityPaths = City::where('active', true)->pluck('slug', 'legacy_path');
        $image = $this->jpeg();
        Http::fake(function (Request $request) use ($cities, $cityPaths, $image) {
            $path = parse_url($request->url(), PHP_URL_PATH) ?: '/';
            if (str_starts_with($path, '/img/')) {
                return Http::response($image, 200, ['Content-Type' => 'image/jpeg']);
            }
            if (str_starts_with($path, '/car/')) {
                return Http::response('<div class="car_inner_img" data-fancybox="car_gal" data-src="/img/cars/main.jpg"></div><div class="car_inner_img" data-fancybox="car_gal" data-src="/img/photos/side.jpg"></div>'
                    .'<div class="term_item"><div class="term_duration">3-7 дней</div><div class="term_discount">Скидка 10 %</div></div>'
                    .'<div class="term_item"><div class="term_duration">25-30 дней</div><div class="term_discount">Скидка 30 %</div></div>');
            }
            $cars = ($cityPaths[$path] ?? null) === 'astana' ? $cities['astana'] : $cities['other'];

            return Http::response(collect($cars)->map(fn (array $car): string => '<div class="car_item way"><p class="car_name">'.e($car[1]).'</p><p>Цена за сутки:<span>'.number_format($car[2], 0, '', ' ').' тг.</span></p><a class="car_btn" href="/car/'.$car[0].'">Подробнее</a></div>')->implode(''));
        });
    }

    private function jpeg(): string
    {
        $image = imagecreatetruecolor(320, 200);
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }
}
