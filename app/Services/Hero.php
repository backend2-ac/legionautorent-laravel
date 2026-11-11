<?php

namespace App\Services;

use App\Models\SiteSettings;

class Hero
{
    public static function context(SiteSettings $site): array
    {
        $defaults = ['ru' => ['от 20 000 ₸ / сутки', 'Работаем 24/7, без выходных · Подача и приём авто в любой точке города — входит в стоимость'], 'kk' => ['тәулігіне 20 000 ₸ бастап', 'Тәулік бойы, демалыссыз жұмыс істейміз · Көлікті қаланың кез келген нүктесіне жеткізу және қабылдау — бағаға кіреді'], 'en' => ['from 20 000 KZT per day', 'Open 24/7, no days off · Delivery and return anywhere in the city — included in the price']];
        $lang = app()->getLocale();
        $seq = [];
        if ($site->enable_hero_video && preg_match('#^/(static|media)/[a-zA-Z0-9_./-]+\.mp4$#', $site->hero_video_path) && ! str_contains($site->hero_video_path, '..')) {
            $manifest = dirname(public_path(ltrim($site->hero_video_path, '/'))).'/sequence.json';
            if (is_file($manifest)) {
                $d = json_decode(file_get_contents($manifest), true);
                if (($d['master'] ?? null) === $site->hero_video_path) {
                    $seq = ['preview' => $d['preview'] ?? [], 'count' => $d['count'], 'fps' => $d['fps'], 'duration' => $d['duration'], 'packSize' => $d['pack_size']];
                    foreach (array_filter(['mobile', 'desktop', isset($d['jpeg']) ? 'jpeg' : null, isset($d['fullhd']) ? 'fullhd' : null, isset($d['portrait']) ? 'portrait' : null]) as $n) {
                        $seq[$n] = ['width' => $d[$n]['width'], 'height' => $d[$n]['height'], 'bytes' => $d[$n]['bytes'], 'packs' => array_column($d[$n]['packs'], 'url')];
                    }
                }
            }
        }

        $poster = $site->hero_poster_path ?: '/static/hero/hero-poster-60fps-desktop.webp';
        $mobilePoster = $site->hero_mobile_poster_path ?: $site->hero_poster_path;
        $ending = $site->hero_ending_path;
        if ($seq && $poster === '/static/hero/hero-poster-60fps-desktop.webp') {
            $poster = $d['fullhd']['poster'] ?? $poster;
        }
        if ($seq && isset($d['portrait'])) {
            $poster = $d['fullhd']['poster'] ?? $poster;
            $mobilePoster = $d['portrait']['poster'] ?? $mobilePoster;
            $ending = $d['ending'] ?? $ending;
        }

        return ['video' => $site->enable_hero_video ? $site->hero_video_path : '', 'poster' => $poster, 'mobile' => $mobilePoster, 'fallback_poster' => self::posterFallback($poster), 'fallback_mobile' => self::posterFallback($mobilePoster), 'ending' => $ending, 'sequence' => $seq, 'fps' => $seq['fps'] ?? $site->hero_video_fps, 'bytes' => $seq['desktop']['bytes'] ?? 0, 'width' => 1280, 'height' => 720, 'alt' => ['ru' => 'Чёрные внедорожники LEGIONAUTORENT под навесом с флагом Казахстана в Астане', 'kk' => 'Астанадағы Қазақстан туы бейнеленген шатыр астындағы LEGIONAUTORENT қара жол талғамайтын көліктері', 'en' => 'Black LEGIONAUTORENT SUVs under the Kazakhstan flag canopy in Astana'][$lang], 'hero_price_caption' => localized($site, 'hero_price_caption') ?: $defaults[$lang][0], 'hero_steps_caption' => localized($site, 'hero_steps_caption') ?: $defaults[$lang][1]];
    }

    private static function posterFallback(?string $path): string
    {
        $path = $path ?: '/static/hero/hero-poster-60fps-desktop.webp';
        $jpeg = preg_replace('/\.webp$/i', '.jpg', $path);

        return is_file(public_path(ltrim($jpeg, '/'))) ? $jpeg : $path;
    }
}
