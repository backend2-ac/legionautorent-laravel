<?php

namespace Tests\Feature;

use App\Models\ContentBlock;
use App\Models\SiteSection;
use Illuminate\Validation\ValidationException;
use Tests\CatalogueTestCase;

class InstagramReviewsTest extends CatalogueTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        SiteSection::firstOrCreate(['key' => 'reviews'], ['active' => true, 'sort_order' => 48]);
    }

    public function test_the_section_stays_hidden_until_a_reel_is_added(): void
    {
        $this->get('/')->assertOk()->assertDontSee('data-reels', false);

        ContentBlock::create(['kind' => 'reel', 'title' => 'Поездка на Lexus LX', 'text' => 'https://www.instagram.com/reels/AbC_123-x/?igsh=xyz', 'active' => true, 'sort_order' => 1]);

        $response = $this->get('/')->assertOk()->assertSee('data-reels', false)->assertSee('Поездка на Lexus LX');
        $response->assertSee('data-instgrm-permalink="https://www.instagram.com/reel/AbC_123-x/"', false);
        $this->assertStringContainsString('https://www.instagram.com', $response->headers->get('Content-Security-Policy'));
    }

    public function test_only_instagram_reel_or_post_links_are_accepted(): void
    {
        foreach (['https://example.com/reel/abc/', 'http://www.instagram.com/reel/abc/', 'https://www.instagram.com/legionautorent.kz/', 'просто текст'] as $invalid) {
            try {
                ContentBlock::create(['kind' => 'reel', 'title' => 'Отзыв', 'text' => $invalid, 'active' => true, 'sort_order' => 1]);
                $this->fail('Accepted: '.$invalid);
            } catch (ValidationException) {
                $this->assertSame(0, ContentBlock::where('kind', 'reel')->count());
            }
        }
    }
}
