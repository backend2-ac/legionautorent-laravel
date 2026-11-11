<?php

namespace Tests\Feature;

use App\Models\City;
use Tests\CatalogueTestCase;

class CityNavigationAndRequestTest extends CatalogueTestCase
{
    public function test_city_navigation_opens_the_city_catalogue_from_editorial_pages_in_each_language(): void
    {
        $this->artisan('legion:publish-locales')->assertSuccessful();
        foreach (['', '/kk', '/en'] as $prefix) {
            foreach (['/contacts/', '/rental-conditions/'] as $path) {
                $response = $this->get($prefix.$path.'?city=kostanay')->assertOk();
                $href = $this->cityLink($response->getContent(), 'ustkamenogorsk');
                $this->assertSame($prefix.'/cars/ustkamenogorsk/', $href);
                $changed = $this->get($href)->assertOk();
                $this->assertSame($prefix.'/cars/ustkamenogorsk/', parse_url($href, PHP_URL_PATH));
                $this->assertStringContainsString(localized(City::where('slug', 'ustkamenogorsk')->first(), 'name'), $changed->getContent());
            }
        }
    }

    public function test_city_switch_keeps_catalogue_prices_sorting_and_home_behaviour(): void
    {
        $this->artisan('legion:publish-locales')->assertSuccessful();
        $response = $this->get('/cars/kostanay/?min_price=25000&max_price=80000&sort=-price')->assertOk();
        $href = $this->cityLink($response->getContent(), 'ustkamenogorsk');
        $this->assertSame('/cars/ustkamenogorsk/?min_price=25000&max_price=80000&sort=-price', $href);
        $this->get($href)->assertOk()->assertSee('value="25000"', false)->assertSee('value="80000"', false)->assertSee('value="-price" selected', false);
        $home = $this->get('/kostanay/')->assertOk();
        $this->assertSame('/cars/ustkamenogorsk/', $this->cityLink($home->getContent(), 'ustkamenogorsk'));
        $this->assertSame('/cars/astana/', $this->cityLink($home->getContent(), 'astana'));
    }

    public function test_city_navigation_replaces_the_dropdown_and_general_fleet_entry(): void
    {
        foreach (['', '/kk', '/en'] as $prefix) {
            $response = $this->get($prefix.'/cars/kostanay/')->assertOk();
            $dom = new \DOMDocument;
            @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent());
            $xpath = new \DOMXPath($dom);
            $this->assertSame(4, $xpath->query('//nav[@class="city-navigation"]/a')->length);
            $this->assertSame(1, $xpath->query('//nav[@class="city-navigation"]/a[@data-city-switch="kostanay"][@aria-current="location"]')->length);
            $this->assertSame(0, $xpath->query('//details[@class="city-menu"]')->length);
            $this->assertSame(0, $xpath->query('//a[@data-site-nav][contains(@href,"/cars/")]')->length);
            $this->get($prefix.'/cars/?max_price=50000')->assertStatus(302)->assertHeader('Location', $prefix.'/cars/kostanay/?max_price=50000');
            $redirect = $this->get($prefix.'/cars/')->assertStatus(302)->assertHeader('Location', $prefix.'/cars/kostanay/');
            $this->get($redirect->headers->get('Location'))->assertOk();
        }
    }

    public function test_conditions_keep_cms_text_with_an_aligned_reading_block(): void
    {
        $this->artisan('legion:publish-locales')->assertSuccessful();
        foreach (['', '/kk', '/en'] as $prefix) {
            $response = $this->get($prefix.'/rental-conditions/')->assertOk();
            $response->assertSee('conditions-page', false)->assertSee('<div class="page-copy">', false);
            $this->assertSame(1, substr_count($response->getContent(), '<h1>'));
            $dom = new \DOMDocument;
            @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent());
            $this->assertSame(5, (new \DOMXPath($dom))->query('//div[@class="page-copy"]/h2')->length);
        }
    }

    private function cityLink(string $html, string $slug): string
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        $links = (new \DOMXPath($dom))->query('//a[@data-city-switch="'.$slug.'"]');
        $this->assertSame(1, $links->length);

        return $links->item(0)->getAttribute('href');
    }
}
