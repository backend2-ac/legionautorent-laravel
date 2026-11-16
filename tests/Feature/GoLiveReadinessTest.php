<?php

namespace Tests\Feature;

use App\Models\Car;
use App\Models\SiteSettings;
use Tests\CatalogueTestCase;

class GoLiveReadinessTest extends CatalogueTestCase
{
    public function test_www_redirects_permanently_to_the_main_domain_once_live(): void
    {
        config(['legion.site_url' => 'https://legionautorent.kz']);

        $this->get('https://www.legionautorent.kz/contacts/?city=astana')->assertStatus(301)->assertRedirect('https://legionautorent.kz/contacts/?city=astana');
        $this->get('https://legionautorent.kz/contacts/')->assertOk();

        config(['legion.staging' => true]);
        $this->get('https://www.legionautorent.kz/contacts/')->assertOk();
    }

    public function test_live_pages_load_metrika_and_allow_google_ads_and_metrika_hosts(): void
    {
        SiteSettings::findOrFail(1)->forceFill(['gtm_id' => 'GTM-KJWPLLN', 'metrika_id' => '92545653', 'google_verification' => 'google-code', 'yandex_verification' => 'yandex-code'])->saveQuietly();
        config(['legion.analytics' => true]);

        $response = $this->get('/')->assertOk()
            ->assertSee('data-gtm="GTM-KJWPLLN"', false)
            ->assertSee('data-metrika="92545653"', false)
            ->assertSee('<meta name="google-site-verification" content="google-code">', false)
            ->assertSee('yandex-code', false);

        $policy = $response->headers->get('Content-Security-Policy');
        foreach (['https://www.googleadservices.com', 'https://td.doubleclick.net', 'https://mc.yandex.com', 'wss://mc.yandex.ru', 'https://*.analytics.google.com'] as $host) {
            $this->assertStringContainsString($host, $policy);
        }
    }

    public function test_a_car_whose_description_is_only_its_name_gets_a_real_description(): void
    {
        $car = Car::public()->with('cities')->firstOrFail();
        $car->forceFill(['seo_title' => $car->name, 'seo_description' => $car->name])->saveQuietly();

        $this->get($car->legacy_path)->assertOk()
            ->assertSee('<meta name="description" content="'.e('Аренда '.$car->name.' без водителя'), false)
            ->assertDontSee('<meta name="description" content="'.e($car->name).'">', false);
    }
}
