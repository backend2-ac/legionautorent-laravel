<?php

namespace Tests\Feature;

use App\Models\SiteSettings;
use App\Services\Hero;
use Tests\CatalogueTestCase;

class HeroPreviewTest extends CatalogueTestCase
{
    public function test_chapters_progressively_cover_every_frame_without_gaps_and_fit_budgets(): void
    {
        $manifest = json_decode(file_get_contents(public_path('static/hero-film/manifest.json')), true, 512, JSON_THROW_ON_ERROR);
        $indices = [];
        foreach ($manifest['chapters'] as $chapter) {
            foreach (range($chapter['start'], $chapter['end']) as $index) {
                $this->assertNotContains($index, $indices);
                $indices[] = $index;
            }
        }
        $this->assertSame(range(0, $manifest['count'] - 1), $indices);
        $this->assertLessThanOrEqual(5000000, $manifest['desktop']['bytes']);
        $this->assertLessThanOrEqual(3000000, $manifest['mobile']['bytes']);
        $this->assertLessThanOrEqual(60000, filesize(public_path(ltrim($manifest['poster'], '/'))));
        $scenes = json_decode(file_get_contents(resource_path('hero-source/scenes.json')), true);
        $this->assertCount(count($scenes['scenes']), $manifest['chapters']);
        $sheet = getimagesize(base_path('reports/hero-film/contact-sheet.jpg'));
        $this->assertSame(180 * count($scenes['scenes']), $sheet[0]);
    }

    public function test_hero_exposes_both_resolutions_and_preserves_static_content_when_disabled(): void
    {
        $site = new SiteSettings(['enable_hero_video' => true]);
        $hero = Hero::context($site);
        $this->assertSame(720, $hero['sequence']['desktop']['width']);
        $this->assertSame(540, $hero['sequence']['mobile']['width']);
        $this->assertCount(7, $hero['chapters']);
        $site->enable_hero_video = false;
        $this->assertNull(Hero::context($site)['sequence']);
        $this->assertSame($hero['poster'], Hero::context($site)['poster']);
        $this->assertSame($hero['chapters'], Hero::context($site)['chapters']);
    }
}
