<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Page;
use App\Services\SeoRelease;
use Tests\CatalogueTestCase;

class SeoReviewTest extends CatalogueTestCase
{
    public function test_review_copy_is_published_once_and_remains_editable(): void
    {
        $this->artisan('legion:publish-locales')->assertSuccessful();
        $review = json_decode(file_get_contents(resource_path('data/seo-review-2026-10.json')), true);
        foreach ($review['pages'] as $row) {
            $response = $this->get($row['path'])->assertOk();
            foreach ($row['fields'] as $field => $value) {
                $response->assertSee(e($value), false);
            }
            $response->assertSee('content="index,follow"', false);
        }
        $city = City::where('slug', 'kostanay')->firstOrFail();
        $city->update(['seo_description' => 'Описание администратора']);
        $page = Page::where('path', '/cars/pavlodar/')->firstOrFail();
        $page->update(['seo_title' => 'Новый заголовок автопарка']);
        $this->artisan('legion:publish-locales')->assertSuccessful();
        $this->assertSame('Описание администратора', $city->fresh()->seo_description);
        $this->get('/cars/pavlodar/')->assertSee('<title>Новый заголовок автопарка</title>', false);
    }

    public function test_city_urls_redirect_once_keep_attribution_and_do_not_depend_on_previous_city(): void
    {
        SeoRelease::apply();
        foreach (['', '/kk', '/en'] as $prefix) {
            foreach (City::where('active', true)->get() as $city) {
                $target = $prefix.$city->legacy_path;
                $this->get($target.'?city='.$city->slug.'&utm_source=seo')->assertStatus(301)->assertHeader('Location', $target.'?utm_source=seo');
                $response = $this->get($target.'?utm_source=seo')->assertOk();
                $response->assertSee('rel="canonical" href="https://legionautorent.kz'.$target.'"', false);
                $response->assertDontSee('href="'.$target.'?city=', false);
            }
            $this->get($prefix.'/')->assertOk()->assertSee('data-city-home href="'.$prefix.'/"', false);
            $this->get($prefix.'/cars/?city=kostanay&max_price=70000')->assertStatus(301)->assertHeader('Location', $prefix.'/cars/kostanay/?max_price=70000');
        }
        $this->get('/kostanay/?city=astana')->assertRedirect('/');
        $this->get('/cars/unknown/')->assertNotFound();
    }

    public function test_city_fleets_have_matching_cars_canonicals_translations_and_sitemap_entries(): void
    {
        $this->artisan('legion:publish-locales')->assertSuccessful();
        foreach (City::where('active', true)->get() as $city) {
            foreach (['', '/kk', '/en'] as $prefix) {
                $path = $prefix.$city->getCatalogPath();
                $response = $this->get($path)->assertOk();
                $this->assertSame($city->cars()->public()->count(), substr_count($response->getContent(), 'class="car-card"'));
                $response->assertSee('rel="canonical" href="https://legionautorent.kz'.$path.'"', false)->assertSee('content="index,follow"', false);
                $other = City::where('id', '!=', $city->id)->where('active', true)->firstOrFail();
                $response->assertSee('href="'.$prefix.$other->getCatalogPath().'"', false);
                $this->get('/sitemap-pages.xml')->assertSee('https://legionautorent.kz'.$path.'</loc>', false);
            }
        }
        $this->get('/cars/')->assertOk();
        $this->assertSame(91, substr_count($this->get('/cars/')->getContent(), 'class="car-card"'));
        $this->get('/cars/pavlodar/?max_price=25000')->assertSee('content="noindex,follow"', false)->assertSee('rel="canonical" href="https://legionautorent.kz/cars/pavlodar/"', false);
        $this->get('/cars/pavlodar/?utm_source=review')->assertSee('content="index,follow"', false);
        $this->withHeader('X-Legion-Partial', 'catalog')->get('/cars/kostanay/?sort=price')->assertHeader('X-Legion-Robots', 'noindex,follow');
    }

    public function test_hidden_city_or_fleet_page_is_not_published_or_listed_in_sitemap(): void
    {
        SeoRelease::apply();
        $city = City::where('slug', 'kostanay')->firstOrFail();
        $city->update(['active' => false]);
        $this->get('/cars/kostanay/')->assertNotFound();
        $this->get('/sitemap-pages.xml')->assertDontSee('/cars/kostanay/');
        Page::where('path', '/cars/pavlodar/')->firstOrFail()->update(['active' => false]);
        $this->get('/cars/pavlodar/')->assertNotFound();
        $this->get('/sitemap-pages.xml')->assertDontSee('/cars/pavlodar/');
    }

    public function test_staging_remains_blocked_and_analytics_are_not_enabled_by_the_seo_review(): void
    {
        $this->artisan('legion:publish-locales')->assertSuccessful();
        config(['legion.staging' => true, 'legion.analytics' => true]);
        foreach (['/', '/cars/astana/', '/contacts/'] as $path) {
            $this->get($path)->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertSee('content="noindex,nofollow"', false)->assertDontSee('data-analytics', false);
        }
        $this->get('/robots.txt')->assertSee('Disallow: /');
        config(['legion.staging' => false]);
        $this->get('/')->assertHeaderMissing('X-Robots-Tag')->assertSee('content="index,follow"', false)->assertSee('data-analytics', false);
        $this->get('/admin/login')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
