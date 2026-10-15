<?php

namespace Tests\Feature;

use App\Models\Car;
use App\Models\SiteSettings;
use App\Services\Hero;
use Tests\CatalogueTestCase;

class FullscreenHeroAndNavigationTest extends CatalogueTestCase
{
    public function test_admin_uses_the_new_address_and_stays_out_of_search_results(): void
    {
        $this->assertSame('/admin/login', route('filament.admin.auth.login', [], false));
        $this->get('/admin')->assertRedirect(route('filament.admin.auth.login'));
        $this->get('/admin/login')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get('/robots.txt')->assertOk()->assertSee('Disallow: /admin/', false);
        $this->actingAs($this->owner());
        $this->get('/admin')->assertOk();
        $this->get('/admin/cars/'.Car::first()->id.'/edit')->assertOk();
    }

    public function test_existing_admin_bookmarks_redirect_to_the_same_new_screen(): void
    {
        foreach (['' => '/admin', '/login' => '/admin/login', '/cars/1/edit?tab=photos' => '/admin/cars/1/edit?tab=photos'] as $old => $new) {
            $this->get('/control-legion'.$old)->assertStatus(301)->assertRedirect($new)->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        }
    }

    public function test_breadcrumbs_are_readable_city_aware_and_do_not_link_city_home_to_itself(): void
    {
        $this->artisan('legion:publish-locales')->assertSuccessful();
        foreach (['', '/kk', '/en'] as $prefix) {
            $home = $this->get($prefix.'/kostanay/')->assertOk();
            $dom = new \DOMDocument;
            @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$home->getContent());
            $xpath = new \DOMXPath($dom);
            $this->assertSame(1, $xpath->query('//nav[@class="breadcrumbs container"]/ol/li')->length);
            $this->assertSame(0, $xpath->query('//nav[@class="breadcrumbs container"]//a')->length);
            $this->assertSame(1, $xpath->query('//nav[@class="breadcrumbs container"]//*[@aria-current="page"]')->length);
            $home->assertDontSee('"@type":"BreadcrumbList"', false);
            foreach (['/contacts/', '/rental-conditions/', '/cars/kostanay/'] as $path) {
                $page = $this->get($prefix.$path)->assertOk();
                @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$page->getContent());
                $xpath = new \DOMXPath($dom);
                $links = $xpath->query('//nav[@class="breadcrumbs container"]//a');
                $this->assertSame(1, $links->length);
                $this->assertSame($prefix.'/kostanay/', $links->item(0)->getAttribute('href'));
                $this->get($links->item(0)->getAttribute('href'))->assertOk();
            }
        }
    }

    public function test_chapter_film_has_correct_full_resolution_assets(): void
    {
        $manifest = json_decode(file_get_contents(public_path('static/hero-film/manifest.json')), true, 512, JSON_THROW_ON_ERROR);
        $desktop = $manifest['desktop'];
        $this->assertSame([1280, 720], [$desktop['width'], $desktop['height']]);
        $bytes = 0;
        for ($index = 0; $index < $manifest['count']; $index++) {
            $path = public_path(ltrim($desktop['base'], '/').sprintf('%04d.webp', $index));
            $payload = file_get_contents($path);
            $image = getimagesizefromstring($payload);
            $this->assertSame([1280, 720, 'image/webp'], [$image[0], $image[1], $image['mime']]);
            $bytes += strlen($payload);
        }
        $this->assertCount($manifest['count'], glob(public_path(ltrim($desktop['base'], '/')).'*.webp'));
        $this->assertSame($desktop['bytes'], $bytes);
        $site = SiteSettings::first();
        $site->enable_hero_video = true;
        $hero = Hero::context($site);
        $this->assertSame($manifest, $hero['sequence']);
        $this->assertSame($manifest['poster'], $hero['poster']);
        $this->get('/')->assertOk()->assertSee($hero['poster'], false)->assertSee('fetchpriority="high"', false);
    }
}
