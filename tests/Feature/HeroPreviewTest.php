<?php

namespace Tests\Feature;

use App\Models\SiteSettings;
use App\Services\Hero;
use Tests\TestCase;

class HeroPreviewTest extends TestCase
{
    public function test_each_preview_format_progressively_covers_the_entire_orbit_with_valid_images(): void
    {
        $manifest = json_decode(file_get_contents(public_path('static/hero/sequence.json')), true, 512, JSON_THROW_ON_ERROR);
        foreach (['webp', 'jpeg'] as $format) {
            $variant = $manifest['preview'][$format];
            $indices = [];
            $total = 0;
            foreach ($variant['packs'] as $position => $pack) {
                $payload = file_get_contents(public_path(ltrim($pack['url'], '/')));
                $this->assertSame($pack['sha256'], hash('sha256', $payload));
                $this->assertSame($pack['bytes'], strlen($payload));
                $offset = 0;
                foreach ($pack['indices'] as $index) {
                    $this->assertNotContains($index, $indices);
                    $size = unpack('Vsize', substr($payload, $offset, 4))['size'];
                    $offset += 4;
                    $image = getimagesizefromstring(substr($payload, $offset, $size));
                    $this->assertSame([640, 360, 'image/'.$format], [$image[0], $image[1], $image['mime']]);
                    $offset += $size;
                    $indices[] = $index;
                }
                $this->assertSame(strlen($payload), $offset);
                $total += $offset;
                sort($indices);
                if ($position === 0) {
                    $this->assertSame(0, $indices[0]);
                    $this->assertSame($manifest['count'] - 1, end($indices));
                    for ($i = 1; $i < count($indices); $i++) {
                        $this->assertLessThanOrEqual(20, $indices[$i] - $indices[$i - 1]);
                    }
                }
            }
            $this->assertSame(range(0, 595, 5), $indices);
            $this->assertSame($variant['bytes'], $total);
            $this->assertLessThan($format === 'webp' ? 1024 * 1024 : 2 * 1024 * 1024, $total);
        }
    }

    public function test_hero_exposes_preview_and_original_full_resolution_sequences_without_needing_video(): void
    {
        $site = new SiteSettings([
            'enable_hero_video' => true,
            'hero_video_path' => '/static/hero/hero-60fps.mp4',
            'hero_poster_path' => '/static/hero/hero-poster-60fps-desktop.webp',
        ]);
        $hero = Hero::context($site);
        $this->assertCount(4, $hero['sequence']['preview']['webp']['packs']);
        $this->assertCount(4, $hero['sequence']['preview']['jpeg']['packs']);
        $this->assertSame(596, $hero['sequence']['count']);
        $this->assertSame(1920, $hero['sequence']['fullhd']['width']);
        $site->enable_hero_video = false;
        $this->assertSame([], Hero::context($site)['sequence']);
    }
}
