<?php

namespace App\Services;

use App\Models\Car;
use App\Models\CarBrand;
use App\Models\CarCategory;
use App\Models\CarDiscount;
use App\Models\CarImage;
use App\Models\CarPrice;
use App\Models\City;
use App\Models\CmsModel;
use App\Models\ContentType;
use App\Models\MenuLink;
use App\Models\Page;
use App\Models\Redirect;
use App\Models\SiteSection;
use App\Models\SiteSettings;
use App\Models\Translation;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CmsValidation
{
    public static function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }

    public static function path(string $p): bool
    {
        return str_starts_with($p, '/') && ! str_starts_with($p, '//') && ! preg_match('/[?#\\\\\x00-\x1f]/', $p);
    }

    public static function exists(string $p): bool
    {
        return in_array($p, ['/', '/cars/', '/faq/', '/booking/', '/callback/', '/request-success/']) || Car::where('legacy_path', $p)->public()->exists() || City::where('legacy_path', $p)->where('active', true)->exists() || Page::where('path', $p)->where('active', true)->exists() || CarCategory::where('slug', trim(str_replace('/category/', '', $p), '/'))->where('active', true)->exists();
    }

    public static function rangesOverlap(int $from, int $to, int $otherFrom, int $otherTo): bool
    {
        $commonBoundary = ($to === $otherFrom || $otherTo === $from) && $from !== $otherFrom;

        return $from <= $otherTo && $otherFrom <= $to && ! $commonBoundary;
    }

    public static function validate(CmsModel $m): void
    {
        foreach ($m->definition()['fields'] ?? [] as $f) {
            $n = $f['column'];
            if ($f['primary'] || $f['nullable'] || in_array($n, ['created_at', 'updated_at']) || ($m instanceof Car && in_array($n, ['brand_id', 'category_id']))) {
                continue;
            }if (! array_key_exists($n, $m->getAttributes()) || $m->getAttributes()[$n] === null) {
                $m->$n = $f['default'] ?? match ($f['type']) {
                    'JSONField' => [],'BooleanField' => false,default => str_contains($f['type'], 'Integer') || $f['type'] === 'DecimalField' ? 0 : ''
                };
            }
        }
        if ($m instanceof SiteSection && in_array($m->key, ['fleet', 'seo']) && ! $m->active) {
            self::fail('active', 'Автопарк и SEO-текст должны оставаться доступными.');
        }
        if ($m instanceof SiteSettings && $m->gtm_id && ! preg_match('/^GTM-[A-Z0-9]+$/', $m->gtm_id)) {
            self::fail('gtm_id', 'Укажите GTM-...');
        }
        if ($m instanceof Page && ! $m->path) {
            $m->path = '/'.$m->slug.'/';
        }
        if ($m instanceof Car) {
            if (! $m->exists && ! $m->brand_id) {
                $name = mb_strtolower(trim($m->name));
                $brand = CarBrand::all(['id', 'name'])->sortByDesc(fn (CarBrand $brand): int => mb_strlen($brand->name))
                    ->first(function (CarBrand $brand) use ($name): bool {
                        $brandName = mb_strtolower($brand->name);

                        return $name === $brandName || str_starts_with($name, $brandName.' ');
                    });
                $m->brand_id = $brand?->id;
            }
            if (! $m->legacy_path) {
                $m->legacy_path = '/car/'.$m->slug;
            }if (! $m->legacy_id) {
                $m->legacy_id = 'cms:'.Str::uuid();
            }if ($m->base_price < 1) {
                self::fail('base_price', 'Укажите положительную цену.');
            }
        }
        if ($m instanceof City && ! $m->legacy_path) {
            $m->legacy_path = '/'.$m->slug.'/';
        }
        foreach (['legacy_path', 'path', 'old_path', 'new_path'] as $key) {
            if (isset($m->getAttributes()[$key]) && $m->$key && ! self::path($m->$key)) {
                self::fail($key, 'Нужен локальный адрес без домена, query и fragment.');
            }
        }
        if ($m instanceof Page || $m instanceof City || $m instanceof Car) {
            $pathKey = $m instanceof Page ? 'path' : 'legacy_path';
            if ($m->isDirty($pathKey)) {
                $path = $m->$pathKey;
                $conflict = collect([Car::class => 'legacy_path', City::class => 'legacy_path', Page::class => 'path'])
                    ->contains(fn (string $column, string $model): bool => $model::where($column, $path)->when($m instanceof $model, fn ($query) => $query->where('id', '!=', $m->id ?? 0))->exists());
                $reserved = [...City::where('active', true)->get()->map(fn (City $city) => $city->getCatalogPath())->all(), '/cars/', '/faq/', '/booking/', '/callback/', '/request-success/', '/healthz/', '/robots.txt', '/sitemap.xml'];
                $installingSystemPage = $m instanceof Page && app()->runningInConsole() && request()->attributes->get('legion.installing_system_pages') === true;
                if ($conflict || (! $installingSystemPage && (! $m->exists || $m->getOriginal($pathKey) !== $path) && in_array($path, $reserved)) || Redirect::where('old_path', $path)->where('active', true)->exists()) {
                    self::fail($pathKey, 'Этот адрес уже занят. Измените slug или укажите другой адрес страницы.');
                }
            }
        }
        if ($m->canonical_url && $m->canonical_url !== config('legion.site_url').$m->getAbsoluteUrl()) {
            self::fail('canonical_url', 'Canonical должен совпадать с собственным публичным URL.');
        }
        if ($m instanceof CarImage && $m->is_main && CarImage::where('car_id', $m->car_id)->where('is_main', true)->where('id', '!=', $m->id ?? 0)->exists()) {
            self::fail('is_main', 'У автомобиля может быть только одно главное фото.');
        }
        if ($m instanceof CarPrice || $m instanceof CarDiscount) {
            if ($m->min_days < 1 || ($m->max_days !== null && $m->max_days < $m->min_days)) {
                self::fail('max_days', 'Проверьте диапазон дней.');
            }if ($m instanceof CarPrice && $m->daily_price < 1) {
                self::fail('daily_price', 'Цена должна быть положительной.');
            }if ($m instanceof CarDiscount && ($m->percent < 1 || $m->percent > 99)) {
                self::fail('percent', 'Скидка: от 1 до 99%.');
            }$q = $m->newQuery()->where('car_id', $m->car_id)->where('id', '!=', $m->id ?? 0)->where(fn ($q) => $q->whereNull('max_days')->orWhere('max_days', '>=', $m->min_days));
            if ($m->max_days !== null) {
                $q->where('min_days', '<=', $m->max_days);
            }$validated = request()->attributes->get('legion.validated_car_relation', []);
            $validatedTogether = ($validated['car_id'] ?? null) === $m->car_id && ($validated['relationship'] ?? null) === ($m instanceof CarPrice ? 'prices' : 'discounts');
            if (! $validatedTogether && $q->get()->contains(fn ($other): bool => self::rangesOverlap((int) $m->min_days, (int) ($m->max_days ?? PHP_INT_MAX), (int) $other->min_days, (int) ($other->max_days ?? PHP_INT_MAX)))) {
                self::fail('min_days', 'Диапазоны тарифов / скидок не должны пересекаться.');
            }
        }
        if ($m instanceof Translation && Translation::where('content_type_id', $m->content_type_id)->where('object_id', $m->object_id)->where('language', $m->language)->where('id', '!=', $m->id ?? 0)->exists()) {
            self::fail('language', 'Для этого объекта уже есть перевод на выбранном языке. Откройте существующий перевод.');
        }
        if ($m instanceof Translation && $m->published) {
            $type = ContentType::find($m->content_type_id)?->model;
            $required = match ($type) {
                'car','city','carcategory' => ['name', 'title', 'description', 'h1', 'content'],'page' => ['title', 'description', 'h1', 'content'],'faq','contentblock' => ['title', 'content'],'carimage' => ['name'],'carspecification' => ['name', 'content'],'sitesettings' => ['hero_title', 'hero_text', 'partner_title', 'partner_description', 'partner_whatsapp_message', 'footer_text'],default => []
            };
            foreach ($required as $k) {
                if (! $m->$k) {
                    self::fail($k, 'Для публикации заполните полный перевод.');
                }
            }
        }
        if ($m instanceof Redirect && $m->active) {
            if ($m->old_path === $m->new_path) {
                self::fail('new_path', 'Перенаправление на себя запрещено.');
            }if (self::exists($m->old_path)) {
                self::fail('old_path', 'Работающий URL должен сохранять ответ 200.');
            }if (! self::exists($m->new_path)) {
                self::fail('new_path', 'Укажите существующую конечную страницу.');
            }if (Redirect::where('active', true)->where('id', '!=', $m->id ?? 0)->where(fn ($q) => $q->where('old_path', $m->new_path)->orWhere('new_path', $m->old_path))->exists()) {
                self::fail('new_path', 'Цепочки и циклы запрещены.');
            }
        }
        if ($m instanceof MenuLink && ! self::exists($m->path)) {
            self::fail('path', 'Укажите существующую страницу сайта.');
        }
    }
}
