<?php

namespace Tests\Feature;

use App\Models\SiteSettings;
use App\Services\Hero;
use Tests\CatalogueTestCase;

class HeroFilmTest extends CatalogueTestCase
{
    public function test_committed_frames_are_complete_with_correct_dimensions_and_within_budgets(): void
    {
        $manifest = json_decode(file_get_contents(public_path('static/hero-film/manifest.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(7, $manifest['chapters']);
        $this->assertSame(4, $manifest['overlap']);
        $this->assertSame(0, $manifest['chapters'][0]['start']);
        $this->assertSame($manifest['count'] - 1, $manifest['chapters'][6]['end']);
        foreach ($manifest['chapters'] as $i => $chapter) {
            if ($i > 0) {
                $this->assertSame($manifest['chapters'][$i - 1]['end'] + 1, $chapter['start']);
                $this->assertSame(2, $chapter['start'] - $chapter['transitionStart']);
            }
        }
        foreach (['desktop' => [720, 1280, 5000000], 'mobile' => [540, 960, 3000000]] as $name => [$w, $h, $budget]) {
            $variant = $manifest[$name];
            $bytes = 0;
            for ($i = 0; $i < $manifest['count']; $i++) {
                $file = public_path(ltrim($variant['base'], '/').sprintf('%04d.webp', $i));
                $this->assertFileExists($file);
                $size = getimagesize($file);
                $this->assertSame([$w, $h, 'image/webp'], [$size[0], $size[1], $size['mime']]);
                $bytes += filesize($file);
            }
            $this->assertSame($variant['bytes'], $bytes);
            $this->assertLessThanOrEqual($budget, $bytes);
        }
        $this->assertLessThanOrEqual(60000, filesize(public_path(ltrim($manifest['poster'], '/'))));
        $scenes = json_decode(file_get_contents(resource_path('hero-source/scenes.json')), true);
        foreach ($scenes['scenes'] as $scene) {
            $this->assertTrue($scene['end'] <= 9.4 || $scene['start'] >= 11.3);
        }
    }

    public function test_all_languages_have_static_text_poster_and_booking_without_a_video_element(): void
    {
        foreach (['' => 'Забронировать', '/kk' => 'Брондау', '/en' => 'Book a car'] as $prefix => $booking) {
            $response = $this->get($prefix.'/')->assertOk()->assertSee($booking)->assertSee('fetchpriority="high"', false)->assertSee('data-city-label', false)->assertDontSee('<video', false);
            $dom = new \DOMDocument;
            @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent());
            $xpath = new \DOMXPath($dom);
            $this->assertSame(1, $xpath->query('//h1')->length);
            $this->assertSame(0, $xpath->query('//*[@data-film-caption]')->length);
            $this->assertSame(2, $xpath->query('//section[@data-hero-film]//canvas[@aria-hidden="true"]')->length);
            $this->assertSame('#fleet', $xpath->query('//a[contains(@class,"hero-film__book")]')->item(0)->getAttribute('href'));
        }
    }

    public function test_disabling_motion_preserves_the_poster_and_chapters(): void
    {
        $site = SiteSettings::first();
        $site->enable_hero_video = false;
        $context = Hero::context($site);
        $this->assertNull($context['sequence']);
        $this->assertCount(7, $context['chapters']);
        $this->assertFileExists(public_path(ltrim($context['poster'], '/')));
    }
}
