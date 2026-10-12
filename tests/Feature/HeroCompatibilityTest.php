<?php

namespace Tests\Feature;

use App\Models\SiteSettings;
use App\Services\Hero;
use Tests\CatalogueTestCase;

class HeroCompatibilityTest extends CatalogueTestCase
{
    public function test_jpeg_fallback_has_the_full_orbit_and_valid_frame_packs(): void
    {
        $manifest = json_decode(file_get_contents(public_path('static/hero/sequence.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($manifest['count'], $manifest['jpeg']['count']);
        $this->assertSame($manifest['master_sha256'], hash_file('sha256', public_path(ltrim($manifest['master'], '/'))));
        $frames = 0;
        foreach ($manifest['jpeg']['packs'] as $pack) {
            $bytes = file_get_contents(public_path(ltrim($pack['url'], '/')));
            $this->assertSame($pack['sha256'], hash('sha256', $bytes));
            $offset = 0;
            $count = 0;
            while ($offset < strlen($bytes)) {
                $size = unpack('Vsize', substr($bytes, $offset, 4))['size'];
                $offset += 4;
                $image = getimagesizefromstring(substr($bytes, $offset, $size));
                $this->assertSame('image/jpeg', $image['mime']);
                $this->assertSame($manifest['jpeg']['width'], $image[0]);
                $this->assertSame($manifest['jpeg']['height'], $image[1]);
                $offset += $size;
                $count++;
            }
            $this->assertSame($pack['count'], $count);
            $this->assertSame(strlen($bytes), $offset);
            $frames += $count;
        }
        $this->assertSame($manifest['count'], $frames);
    }

    public function test_hero_context_exposes_compatible_frames_and_both_fallback_posters(): void
    {
        $site = SiteSettings::first();
        $site->enable_hero_video = true;
        $site->hero_video_path = '/static/hero/hero-60fps.mp4';
        $site->hero_poster_path = '/static/hero/hero-poster-60fps-desktop.webp';
        $site->hero_mobile_poster_path = '/static/hero/hero-poster-60fps-mobile.webp';
        $hero = Hero::context($site);
        $this->assertCount(75, $hero['sequence']['jpeg']['packs']);
        $this->assertSame('/static/hero/hero-poster-60fps-desktop.jpg', $hero['fallback_poster']);
        $this->assertSame('/static/hero/hero-poster-60fps-mobile.jpg', $hero['fallback_mobile']);
        $this->assertSame(596, $hero['sequence']['count']);
        $this->get('/')->assertOk()->assertSee('type="image/webp"', false)->assertSee('hero-poster-60fps-desktop.jpg', false)->assertSee('hero-poster-60fps-mobile.jpg', false);
    }
}
