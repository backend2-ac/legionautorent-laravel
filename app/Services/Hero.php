<?php

namespace App\Services;

use App\Models\SiteSettings;

class Hero
{
    public static function context(SiteSettings $site): array
    {
        $manifest = json_decode(file_get_contents(public_path('static/hero-film/manifest.json')), true, 512, JSON_THROW_ON_ERROR);

        return [
            'poster' => $manifest['poster'],
            'sequence' => $site->enable_hero_video ? $manifest : null,
            'chapters' => $manifest['chapters'],
            'alt' => site_text('Mercedes-Brabus: экстерьер и детали'),
        ];
    }
}
