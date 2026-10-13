<?php

namespace App\Services;

use App\Models\Car;
use App\Models\CarCategory;
use App\Models\City;
use App\Models\CmsModel;
use App\Models\FAQ;
use App\Models\Page;
use App\Models\SiteSettings;

class Seo
{
    public static function languages(?CmsModel $obj): array
    {
        $languages = ['ru', ...($obj ? $obj->translations->filter(fn ($t) => $t->published && $t->title && $t->description && $t->h1 && $t->content)->pluck('language')->all() : ['kk', 'en'])];
        if ($obj instanceof Page && (str_starts_with($obj->path, '/cars/') || $obj->path === '/faq/')) {
            $dependencies = str_starts_with($obj->path, '/cars/') ? self::catalogLanguages() : self::faqLanguages();
            $languages = array_values(array_intersect($languages, $dependencies));
        }

        return $languages;
    }

    public static function catalogLanguages(): array
    {
        $all = Car::public()->with('translations')->get()->concat(CarCategory::where('active', true)->with('translations')->get())->concat(City::where('active', true)->with('translations')->get());

        return ['ru', ...collect(['kk', 'en'])->filter(fn ($l) => $all->isNotEmpty() && $all->every(fn ($o) => in_array($l, self::languages($o))))->all()];
    }

    public static function faqLanguages(): array
    {
        $all = FAQ::where('active', true)->whereNull('car_id')->whereNull('page_id')->with('translations')->get();

        return ['ru', ...collect(['kk', 'en'])->filter(fn ($l) => $all->isNotEmpty() && $all->every(fn ($f) => $f->translations->contains(fn ($t) => $t->published && $t->language === $l && $t->title && $t->content)))->all()];
    }

    public static function page(?CmsModel $obj = null, string $title = '', string $description = '', string $h1 = '', bool $noindex = false, ?array $languages = null): array
    {
        if ($obj === null) {
            $obj = Page::where('active', true)->where('path', request()->attributes->get('base_path'))->with('translations')->first();
        }
        $root = config('legion.site_url');
        $path = request()->attributes->get('base_path', '/');
        $lang = app()->getLocale();
        $langs = $languages ?? self::languages($obj);
        if ($obj && $languages !== null) {
            $langs = array_values(array_intersect($langs, self::languages($obj)));
        }
        $fallback = ! in_array($lang, $langs);
        $title = $obj ? localized($obj, 'seo_title') : $title;
        $description = $obj ? localized($obj, 'seo_description') : $description;
        $h1 = $obj ? localized($obj, 'seo_h1') : $h1;
        $canonical = $root.language_url($path);
        if ($lang === 'ru' && $obj?->canonical_url) {
            $canonical = $root.parse_url($obj->canonical_url, PHP_URL_PATH);
        }
        $legacy = $lang === 'ru' ? ($obj?->legacy_meta['open_graph'] ?? []) : [];
        $image = $obj?->og_image ?: ($obj instanceof Car ? $obj->main_image?->display_url : null);
        $image ??= '/static/hero/hero-poster-60fps-desktop.webp';
        if (! preg_match('#^https?://#', $image)) {
            $image = $root.$image;
        } elseif (parse_url($image, PHP_URL_HOST) === 'legionautorent.kz') {
            $image = $root.parse_url($image, PHP_URL_PATH);
        }
        $og = $obj instanceof City ? $canonical : ($legacy['og:url'] ?? $canonical);
        if (parse_url($og, PHP_URL_HOST) === 'legionautorent.kz') {
            $og = $root.parse_url($og, PHP_URL_PATH);
        }

        return ['title' => $title, 'description' => $description, 'h1' => $h1, 'canonical' => $canonical, 'robots' => config('legion.staging') ? 'noindex,nofollow' : ($noindex || $fallback || str_contains($obj?->robots ?? '', 'noindex') ? 'noindex,follow' : 'index,follow'), 'fallback' => $fallback, 'alternates' => array_map(fn ($l) => ['language' => $l, 'url' => $root.language_url($path, $l)], $langs), 'default_url' => $root.$path, 'og_title' => ($obj ? localized($obj, 'og_title') : '') ?: $title, 'og_description' => ($obj ? localized($obj, 'og_description') : '') ?: $description, 'og_image' => $image, 'og_url' => $og, 'og_type' => $legacy['og:type'] ?? 'website', 'og_site_name' => 'LEGIONAUTORENT', 'og_video' => $legacy['og:video'] ?? ''];
    }

    public static function schemas(array $seo, SiteSettings $site, array $ctx): array
    {
        $root = config('legion.site_url');
        $lang = app()->getLocale();
        $city = $ctx['city'] ?? null;
        $car = $ctx['car'] ?? null;
        $faq = $ctx['faqs'] ?? [];
        $result = [['@context' => 'https://schema.org', '@type' => 'WebSite', '@id' => $root.'/#website', 'name' => $site->name, 'url' => $root.'/', 'inLanguage' => $lang], ['@context' => 'https://schema.org', '@type' => 'WebPage', 'name' => $seo['title'], 'url' => $seo['canonical'], 'inLanguage' => $lang, 'isPartOf' => ['@id' => $root.'/#website']]];
        $biz = ['@context' => 'https://schema.org', '@type' => ['AutoRental', 'LocalBusiness'], '@id' => $root.($city?->legacy_path ?? '/').'#business', 'name' => $site->name, 'url' => $root.($city?->legacy_path ?? '/'), 'telephone' => $city?->phone ?: $site->phone];
        $address = localized($city ?? $site, 'address');
        if ($address) {
            $biz['address'] = ['@type' => 'PostalAddress', 'streetAddress' => $address, 'addressCountry' => 'KZ', ...($city ? ['addressLocality' => localized($city, 'name')] : [])];
        }if ($hours = $city?->hours ?: $site->hours) {
            $biz['openingHours'] = $hours;
        }if ($city?->latitude !== null && $city?->longitude !== null) {
            $biz['geo'] = ['@type' => 'GeoCoordinates', 'latitude' => (string) $city->latitude, 'longitude' => (string) $city->longitude];
        }if ($site->instagram) {
            $biz['sameAs'] = [$site->instagram];
        }$result[] = $biz;
        if (count($ctx['breadcrumbs'] ?? []) > 1) {
            $result[] = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => array_map(fn ($b, $i) => ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $b[0], 'item' => $root.$b[1]], $ctx['breadcrumbs'], array_keys($ctx['breadcrumbs']))];
        }
        if ($car) {
            $offer = ['@type' => 'Offer', 'url' => $seo['canonical'], 'price' => (string) $car->base_price, 'priceCurrency' => 'KZT', 'priceSpecification' => ['@type' => 'UnitPriceSpecification', 'price' => (string) $car->base_price, 'priceCurrency' => 'KZT', 'referenceQuantity' => ['@type' => 'QuantitativeValue', 'value' => 1, 'unitCode' => 'DAY']]];
            $vehicle = ['@context' => 'https://schema.org', '@type' => ['Vehicle', 'Product'], 'name' => localized($car, 'name'), 'url' => $seo['canonical'], 'offers' => $offer];
            if ($car->brand) {
                $vehicle['brand'] = ['@type' => 'Brand', 'name' => $car->brand->name];
            }
            if ($car->main_image) {
                $vehicle['image'] = $root.$car->main_image->display_url;
            }if ($car->year) {
                $vehicle['vehicleModelDate'] = (string) $car->year;
            }if ($car->seats) {
                $vehicle['vehicleSeatingCapacity'] = $car->seats;
            }$result[] = $vehicle;
        }
        if (count($faq)) {
            $result[] = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => collect($faq)->map(fn ($f) => ['@type' => 'Question', 'name' => localized($f, 'question'), 'acceptedAnswer' => ['@type' => 'Answer', 'text' => strip_tags((string) richtext(localized($f, 'answer')))]])->all()];
        }

        return $result;
    }
}
