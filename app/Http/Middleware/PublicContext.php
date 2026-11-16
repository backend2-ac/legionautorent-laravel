<?php

namespace App\Http\Middleware;

use App\Services\PublicContent;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PublicContext
{
    /**
     * Script hosts of Google Tag Manager (GA4, Google Ads) and Yandex Metrika, as their CSP guides list them.
     */
    private const ANALYTICS_SCRIPTS = 'https://*.googletagmanager.com https://www.google-analytics.com https://www.googleadservices.com https://googleads.g.doubleclick.net https://www.google.com https://mc.yandex.ru https://mc.yandex.com https://mc.yandex.kz https://yastatic.net';

    /**
     * Hosts those tags send hits and pixels to.
     */
    private const ANALYTICS_HOSTS = 'https://*.googletagmanager.com https://*.google-analytics.com https://*.analytics.google.com https://www.googleadservices.com https://*.g.doubleclick.net https://www.google.com https://www.google.kz https://mc.yandex.ru https://mc.yandex.com https://mc.yandex.kz';

    public function handle(Request $r, Closure $next): Response
    {
        $siteHost = parse_url((string) config('legion.site_url'), PHP_URL_HOST);
        if (! config('legion.staging') && $siteHost && strtolower($r->getHost()) === 'www.'.$siteHost) {
            return new RedirectResponse(config('legion.site_url').$r->getRequestUri(), 301);
        }
        $path = $r->getPathInfo();
        $adminPath = trim(config('legion.admin_path'), '/');
        $private = $r->is($adminPath, $adminPath.'/*', 'control-legion', 'control-legion/*', 'livewire/*');
        $lang = str_starts_with($path, '/kk/') ? 'kk' : (str_starts_with($path, '/en/') ? 'en' : 'ru');
        app()->setLocale($lang);
        $base = $lang === 'ru' ? $path : substr($path, 3);
        if ($base === '') {
            $base = '/';
        }$r->attributes->set('base_path', $base);
        $r->attributes->set('nonce', base64_encode(random_bytes(24)));
        if (! $private && ! in_array($base, ['/healthz', '/healthz/', '/robots.txt'])) {
            $cities = PublicContent::all()['cities'];
            if ($r->query->has('city') && blank($r->query('city'))) {
                $r->session()->forget('selected_city');
            }
            $chosen = $cities->firstWhere('slug', $r->query('city'));
            $catalogCity = $cities->first(fn ($city) => $city->getCatalogPath() === $base);
            $r->attributes->set('catalog_city', $catalogCity);
            if (! $chosen) {
                $chosen = $cities->firstWhere('legacy_path', $base) ?? $catalogCity;
            }if ($chosen) {
                $r->session()->put('selected_city', $chosen->slug);
            }$selected = $chosen ?? $cities->firstWhere('slug', $r->session()->get('selected_city'));
            $r->attributes->set('selected_city', $selected);
            $r->attributes->set('nav_cities', $cities);
            if (! $r->session()->has('attribution')) {
                $data = ['landing_page' => substr($r->getRequestUri(), 0, 600), 'referrer' => substr($r->headers->get('referer', ''), 0, 600)];
                foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'] as $k) {
                    $data[$k] = substr((string) $r->query($k, ''), 0, 200);
                }$r->session()->put('attribution', $data);
            }
        }
        $response = $next($r);
        if (! $private) {
            $nonce = $r->attributes->get('nonce');
            $policy = [
                "default-src 'self'",
                "script-src 'self' 'nonce-{$nonce}' ".self::ANALYTICS_SCRIPTS.' https://www.instagram.com',
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data: blob: ".self::ANALYTICS_HOSTS,
                "font-src 'self'",
                "connect-src 'self' ".self::ANALYTICS_HOSTS.' wss://mc.yandex.ru wss://mc.yandex.com https://pagead2.googlesyndication.com',
                'frame-src blob: https://yandex.ru https://mc.yandex.ru https://mc.yandex.com https://www.googletagmanager.com https://td.doubleclick.net https://www.instagram.com',
                "media-src 'self' blob:",
                "worker-src 'self' blob:",
                "object-src 'none'",
                "base-uri 'self'",
                "form-action 'self'",
                "frame-ancestors 'none'",
            ];
            $response->headers->set('Content-Security-Policy', implode('; ', $policy));
        }
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set('Content-Language', $lang);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-Frame-Options', 'DENY');
        if (config('legion.staging') || $private) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}
