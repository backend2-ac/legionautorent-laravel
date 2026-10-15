<?php

namespace Tests\Feature;

use App\Models\SiteSettings;
use App\Services\Hero;
use Tests\CatalogueTestCase;

class HeroCompatibilityTest extends CatalogueTestCase
{
    public function test_mobile_variant_has_complete_valid_webp_frames(): void
    {
        $manifest = json_decode(file_get_contents(public_path('static/hero-film/manifest.json')), true, 512, JSON_THROW_ON_ERROR);
        $variant = $manifest['mobile'];
        $files = glob(public_path(ltrim($variant['base'], '/')).'*.webp');
        $this->assertCount($manifest['count'], $files);
        $total = 0;
        foreach ($files as $file) {
            $bytes = file_get_contents($file);
            $image = getimagesizefromstring($bytes);
            $this->assertSame('image/webp', $image['mime']);
            $this->assertSame($variant['width'], $image[0]);
            $this->assertSame($variant['height'], $image[1]);
            $total += strlen($bytes);
        }
        $this->assertSame($variant['bytes'], $total);
        $this->assertLessThanOrEqual(3000000, $total);
    }

    public function test_hero_context_exposes_sequence_and_a_browser_independent_static_poster(): void
    {
        $site = SiteSettings::first();
        $site->enable_hero_video = true;
        $hero = Hero::context($site);
        $manifest = json_decode(file_get_contents(public_path('static/hero-film/manifest.json')), true);
        $this->assertSame($manifest, $hero['sequence']);
        $this->assertSame($manifest['poster'], $hero['poster']);
        $this->assertSame([1280, 720], array_slice(getimagesize(public_path(ltrim($hero['poster'], '/'))), 0, 2));
        $this->get('/')->assertOk()->assertSee('type="image/webp"', false)->assertSee($hero['poster'], false)->assertSee('fetchpriority="high"', false)->assertDontSee('<video', false);
    }
}
