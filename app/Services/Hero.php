<?php

namespace App\Services;

use App\Models\SiteSettings;

class Hero
{
    public static function context(SiteSettings $site): array
    {
        $defaults = ['ru' => ['от 20 000 ₸ / сутки', 'Позвоните нам · Доставка в любую точку города · Договор за 10 минут'], 'kk' => ['тәулігіне 20 000 ₸ бастап', 'Бізге қоңырау шалыңыз · Қаланың кез келген жеріне жеткізу · 10 минутта шарт жасау'], 'en' => ['from 20 000 KZT per day', 'Call us · Delivery anywhere in the city · Contract in 10 minutes']];
        $lang = app()->getLocale();
        $seq = [];
        if ($site->enable_hero_video && preg_match('#^/(static|media)/[a-zA-Z0-9_./-]+\.mp4$#', $site->hero_video_path) && ! str_contains($site->hero_video_path, '..')) {
            $manifest = dirname(public_path(ltrim($site->hero_video_path, '/'))).'/sequence.json';
            if (is_file($manifest)) {
                $d = json_decode(file_get_contents($manifest), true);
                if (($d['master'] ?? null) === $site->hero_video_path) {
                    $seq = ['count' => $d['count'], 'fps' => $d['fps'], 'duration' => $d['duration'], 'packSize' => $d['pack_size']];
                    foreach (array_filter(['mobile', 'desktop', isset($d['jpeg']) ? 'jpeg' : null]) as $n) {
                        $seq[$n] = ['width' => $d[$n]['width'], 'height' => $d[$n]['height'], 'bytes' => $d[$n]['bytes'], 'packs' => array_column($d[$n]['packs'], 'url')];
                    }
                }
            }
        }

        return ['video' => $site->enable_hero_video ? $site->hero_video_path : '', 'poster' => $site->hero_poster_path ?: '/static/hero/hero-poster-60fps-desktop.webp', 'mobile' => $site->hero_mobile_poster_path ?: $site->hero_poster_path, 'fallback_poster' => self::posterFallback($site->hero_poster_path ?: '/static/hero/hero-poster-60fps-desktop.webp'), 'fallback_mobile' => self::posterFallback($site->hero_mobile_poster_path ?: $site->hero_poster_path), 'ending' => $site->hero_ending_path, 'sequence' => $seq, 'fps' => $seq['fps'] ?? $site->hero_video_fps, 'bytes' => $seq['desktop']['bytes'] ?? 0, 'width' => 1280, 'height' => 720, 'alt' => ['ru' => 'Чёрный седан в тёмном шоуруме LEGIONAUTORENT', 'kk' => 'LEGIONAUTORENT қараңғы шоурумындағы қара седан', 'en' => 'Black sedan in the dark LEGIONAUTORENT showroom'][$lang], 'hero_price_caption' => localized($site, 'hero_price_caption') ?: $defaults[$lang][0], 'hero_steps_caption' => localized($site, 'hero_steps_caption') ?: $defaults[$lang][1]];
    }

    private static function posterFallback(?string $path): string
    {
        $path = $path ?: '/static/hero/hero-poster-60fps-desktop.webp';
        $jpeg = preg_replace('/\.webp$/i', '.jpg', $path);

        return is_file(public_path(ltrim($jpeg, '/'))) ? $jpeg : $path;
    }
}
