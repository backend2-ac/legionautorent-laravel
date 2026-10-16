<?php

namespace Tests\Feature;

use App\Http\Controllers\BookingController;
use App\Models\BookingRequest;
use App\Models\Car;
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

    public function test_public_request_forms_have_no_dates_and_submission_stores_a_dateless_lead(): void
    {
        $this->artisan('legion:publish-locales')->assertSuccessful();
        $car = Car::public()->where('accepts_requests', true)->first();
        foreach (['', '/kk', '/en'] as $prefix) {
            foreach ([$car->legacy_path, '/booking/', '/contacts/'] as $path) {
                $this->get($prefix.$path)->assertOk()->assertDontSee('name="start_date"', false)->assertDontSee('name="end_date"', false)->assertSee('data-request-feedback-title', false)->assertSee('data-request-feedback-message', false);
            }
        }
        $this->postJson('/booking/', ['name' => 'Проверка без дат', 'phone' => '+77001112233', 'city' => $car->cities->first()->id, 'car' => $car->id, 'consent' => 'on', 'source_token' => BookingController::token($car->legacy_path)])
            ->assertOk()->assertJsonPath('success', true)->assertJsonPath('detail', 'Менеджер свяжется с вами для уточнения деталей.');
        $lead = BookingRequest::firstOrFail();
        $this->assertNull($lead->start_date);
        $this->assertNull($lead->end_date);
        $this->assertSame(BookingController::CONSENT, $lead->consent_text);
        $this->assertStringNotContainsString('дат аренды', $lead->consent_text);
    }

    public function test_legacy_date_errors_remain_clear_and_translated_for_older_clients(): void
    {
        $city = City::first();
        $messages = ['' => ['Дата получения не может быть в прошлом.', 'Дата возврата должна быть позже даты получения.'], '/kk' => ['Көлікті алу күні өткен күн болмауы керек.', 'Көлікті қайтару күні алу күнінен кейін болуы керек.'], '/en' => ['The pickup date cannot be in the past.', 'The return date must be later than the pickup date.']];
        foreach ($messages as $prefix => [$startError, $endError]) {
            $response = $this->postJson($prefix.'/booking/', ['name' => 'Тест', 'phone' => '+77001112233', 'city' => $city->id, 'consent' => 'on', 'source_token' => BookingController::token('/cars/'), 'start_date' => now()->subDays(2)->toDateString(), 'end_date' => now()->subDays(3)->toDateString()]);
            $response->assertStatus(400)->assertJsonPath('errors.start_date.0', $startError)->assertJsonPath('errors.end_date.0', $endError)->assertDontSee('validation.after');
        }
        $this->assertSame(0, BookingRequest::count());
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

    public function test_phone_mask_is_present_and_incomplete_kazakhstan_numbers_are_rejected(): void
    {
        $city = City::first();
        foreach (['' => 'Введите номер полностью: +7 (XXX) XXX-XX-XX.', '/kk' => 'Нөмірді толық енгізіңіз: +7 (XXX) XXX-XX-XX.', '/en' => 'Enter the full number: +7 (XXX) XXX-XX-XX.'] as $prefix => $error) {
            $this->get($prefix.'/booking/')->assertOk()->assertSee('data-phone-mask', false)->assertSee('inputmode="tel"', false)->assertSee('placeholder="+7 (___) ___-__-__"', false);
            $this->postJson($prefix.'/booking/', ['name' => 'Тест', 'phone' => '+7 (701) 234-56-7', 'city' => $city->id, 'consent' => 'on', 'source_token' => BookingController::token('/cars/')])->assertStatus(400)->assertJsonPath('errors.phone.0', $error);
        }
        $this->assertSame(0, BookingRequest::count());
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
