<?php

use App\Models\Car;
use App\Models\CarCategory;
use App\Models\CarDiscount;
use App\Models\City;
use App\Models\Page;
use App\Models\SiteSettings;
use App\Services\PublicContent;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

function truth(mixed $v): bool
{
    return $v instanceof Collection ? $v->isNotEmpty() : (bool) $v;
}
function dv(string $path, array $vars): mixed
{
    $bits = explode('.', $path);
    $v = $vars[array_shift($bits)] ?? null;
    foreach ($bits as $b) {
        if ($v === null) {
            return null;
        }if ($b === 'all' && $v instanceof Collection) {
            continue;
        }if ($b === 'count' && ($v instanceof Collection || is_array($v))) {
            $v = count($v);

            continue;
        }if ($b === 'url' && is_string($v)) {
            $v = $v ? '/media/'.$v : '';

            continue;
        }$v = is_array($v) ? ($v[$b] ?? null) : ($v instanceof Collection ? $v->get(is_numeric($b) ? (int) $b : $b) : ($v->$b ?? null));
    }

    return $v;
}
function df(mixed $value, string $filter, mixed $arg = null): mixed
{
    return match ($filter) {
        'tr' => localized($value, $arg), 'money' => $value !== null ? number_format((float) $value, 0, '.', ' ') : '',
        'local_url' => language_url((string) $value),'phone_link' => '+'.preg_replace('/\D/', '', (string) $value),
        'richtext' => richtext((string) $value),'json_ld' => new HtmlString(json_safe($value)),
        'json_script' => new HtmlString('<script id="'.e($arg).'" type="application/json">'.json_safe($value).'</script>'),
        default => $value
    };
}
function localized(mixed $obj, string $field, ?string $lang = null): mixed
{
    if (! $obj) {
        return '';
    } $lang ??= app()->getLocale();
    $t = $lang === 'ru' ? null : $obj->translations->first(fn ($t) => $t->language === $lang && $t->published);
    if ($t) {
        $map = ['seo_title' => 'title', 'seo_description' => 'description', 'seo_h1' => 'h1', 'body' => 'content', 'text' => 'content', 'answer' => 'content', 'question' => 'title', 'alt' => 'name', 'value' => 'content', 'label' => 'title'];
        $k = $map[$field] ?? $field;
        if ($field === 'description' && ($obj instanceof Car || $obj instanceof CarCategory)) {
            $k = 'content';
        }
        if ($field === 'title' && $obj instanceof Page) {
            return $t->name ?: $t->h1;
        }
        if ($field === 'og_title') {
            return $t->og_title ?: $t->title;
        }if ($field === 'og_description') {
            return $t->og_description ?: $t->description;
        }
        if ($t->$k) {
            return $t->$k;
        }
    }

    return $obj instanceof CarDiscount && $field === 'label' ? preg_replace('/дней/u', site_text('дней'), $obj->$field ?? '') : ($obj->$field ?? '');
}
function language_url(string $path, ?string $lang = null): string
{
    return ['ru' => '', 'kk' => '/kk', 'en' => '/en'][$lang ?? app()->getLocale()].$path;
}
function site_text(string $source): string
{
    static $builtin = null;
    $builtin ??= json_decode(file_get_contents(resource_path('data/interface-translations.json')), true);
    $owner = request()->attributes->get('interface_copy');
    if ($owner === null) {
        $owner = PublicContent::all()['texts'];
        request()->attributes->set('interface_copy', $owner);
    }
    $lang = app()->getLocale();
    $text = $owner->get($source);

    return ($text?->$lang) ?: ($builtin[$lang][$source] ?? $source);
}
function wa_url(mixed $number, mixed $message): string
{
    return 'https://wa.me/'.preg_replace('/\D/', '', (string) $number).'?text='.rawurlencode((string) $message);
}
function car_wa_url(mixed $number, Car $car, ?City $city = null): string
{
    $message = str_replace('%(car)s', localized($car, 'name'), site_text('Здравствуйте! Интересует аренда %(car)s.'));
    if ($city) {
        $message .= ' '.localized($city, 'name');
    }

    return wa_url($number, $message);
}
function car_discount(Car $car): int
{
    return $car->discounts->max('percent') ?? 0;
}
function home_url(?string $lang = null): string
{
    return language_url(request()->attributes->get('selected_city')?->legacy_path ?? '/', $lang);
}
function catalog_url(?City $city = null, ?string $lang = null): string
{
    $city ??= request()->attributes->get('selected_city');

    return language_url($city?->getCatalogPath() ?? '/cars/', $lang);
}
function menu_url(string $path): string
{
    if ($path === '/cars/') {
        return catalog_url();
    }

    return $path === '/' ? home_url() : language_url($path);
}
function city_switch_url(City $city): string
{
    $request = request();
    $path = $request->attributes->get('base_path', '/');
    $query = [];
    if ($path === '/cars/' || $request->attributes->get('catalog_city')) {
        $query = $request->only(['min_price', 'max_price', 'sort']);
    }
    $path = $city->getCatalogPath();

    return language_url($path).($query ? '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
}
function map_link(?City $city, SiteSettings $site): string
{
    $address = $city ? ($city->address ?: $city->name) : $site->address;

    return $address ? 'https://yandex.ru/maps/?text='.rawurlencode($address) : $site->map_url;
}
function map_embed(?City $city, SiteSettings $site): string
{
    if ($city && $city->map_url && ($city->map_url !== $site->map_url || $city->legacy_path === '/')) {
        return $city->map_url;
    }
    if ((! $city || $city->legacy_path === '/') && $site->map_url) {
        return $site->map_url;
    }
    $a = $city ? ($city->address ?: $city->name) : $site->address;
    $a = preg_replace('/\s*,?\s*(?:офис|ВП-|каб\.)\s*.*$/iu', '', $a);

    return $a ? 'https://yandex.ru/map-widget/v1/?'.http_build_query(['mode' => 'search', 'text' => $a, 'z' => $city && $city->address ? 16 : 12]) : '';
}
function build_asset(string $entry): string
{
    if (str_starts_with($entry, 'static/fonts/') && is_file(public_path($entry))) {
        return '/'.$entry;
    }static $manifest = null;
    $manifest ??= json_decode(file_get_contents(public_path('static/build/.vite/manifest.json')), true);

    return '/static/build/'.($manifest[$entry]['file'] ?? $manifest['public/'.$entry]['file'] ?? 'missing-build.js');
}
function critical_css(string $entry): HtmlString
{
    static $cache = [];
    if (! isset($cache[$entry])) {
        $m = json_decode(file_get_contents(public_path('static/build/.vite/manifest.json')), true);
        $cache[$entry] = implode("\n", array_map(fn ($file) => file_get_contents(public_path('static/build/'.$file)), $m[$entry]['css'] ?? []));
    }

    return new HtmlString(str_replace('</style', '<\\/style', $cache[$entry]));
}
function json_safe(mixed $v): string
{
    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}
function richtext(string $text): HtmlString
{
    static $purifier = null;
    if (! $purifier) {
        $cfg = HTMLPurifier_Config::createDefault();
        $cfg->set('HTML.Allowed', 'p,h2,h3,h4,strong,em,ul,ol,li,a[href|title],br,blockquote');
        $cfg->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true, 'tel' => true]);
        $cfg->set('Cache.SerializerPath', storage_path('framework/cache'));
        $purifier = new HTMLPurifier($cfg);
    }
    $text = $purifier->purify($text);
    $text = preg_replace_callback('/<a\b[^>]*>/i', function ($m) {
        $tag = $m[0];
        preg_match('/href="([^"]*)"/', $tag, $a);
        $host = parse_url(html_entity_decode($a[1] ?? ''), PHP_URL_HOST);

        return substr($tag, 0, -1).' rel="noopener noreferrer"'.(in_array($host, ['wa.me', 'api.whatsapp.com', 'web.whatsapp.com', 'www.whatsapp.com']) ? ' target="_blank"' : '').'>';
    }, $text);

    return new HtmlString($text);
}
function icon(string $name): HtmlString
{
    return new HtmlString('<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><use href="#legion-icon-'.e($name).'"/></svg>');
}
function icon_sprite(): HtmlString
{
    $icons = json_decode(file_get_contents(resource_path('data/icons.json')), true);
    $s = '';
    foreach ($icons as $name => $paths) {
        $s .= '<symbol id="legion-icon-'.$name.'" viewBox="0 0 24 24">'.$paths.'</symbol>';
    }

    return new HtmlString('<svg class="icon-definitions" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">'.$s.'</svg>');
}
function first_value(mixed ...$values): mixed
{
    foreach ($values as $v) {
        if (truth($v)) {
            return $v;
        }
    }

    return '';
}
