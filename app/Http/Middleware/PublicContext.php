<?php

namespace App\Http\Middleware;

use App\Services\PublicContent;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PublicContext
{
    public function handle(Request $r, Closure $next): Response
    {
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
            if (($r->query->has('city') && blank($r->query('city'))) || ($base === '/cars/' && ! $r->query->has('city'))) {
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
            $policy = ["default-src 'self'", "script-src 'self' 'nonce-{$nonce}' https://www.googletagmanager.com https://www.google-analytics.com https://mc.yandex.ru", "style-src 'self' 'unsafe-inline'", "img-src 'self' data: blob: https://mc.yandex.ru https://www.google-analytics.com", "font-src 'self'", "connect-src 'self' https://*.google-analytics.com https://*.googletagmanager.com https://mc.yandex.ru", 'frame-src https://yandex.ru https://www.googletagmanager.com', "media-src 'self' blob:", "worker-src 'self' blob:", "object-src 'none'", "base-uri 'self'", "form-action 'self'", "frame-ancestors 'none'"];
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
