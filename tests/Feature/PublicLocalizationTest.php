<?php

namespace Tests\Feature;

use App\Http\Controllers\BookingController;
use App\Models\BookingRequest;
use App\Models\Car;
use App\Models\City;
use App\Models\FAQ;
use App\Models\Page;
use App\Models\Translation;
use Tests\CatalogueTestCase;

class PublicLocalizationTest extends CatalogueTestCase
{
    private function publish(): void
    {
        $this->artisan('legion:publish-locales')->assertSuccessful();
    }

    public function test_complete_translations_are_published_once_without_overwriting_edits(): void
    {
        $before = Car::first()->only(['seo_title', 'seo_description', 'seo_h1', 'legacy_path', 'base_price']);
        $this->publish();
        $this->assertSame(874, Translation::where('published', true)->count());
        $this->assertStringNotContainsString('Заполните текст в админке', City::where('slug', 'pavlodar')->first()->body);
        $this->assertSame($before, Car::first()->only(array_keys($before)));
        $translation = Translation::where('language', 'en')->where('content_type_id', Car::first()->contentTypeId())->first();
        $translation->title = 'Edited by the administrator';
        $translation->save();
        $this->publish();
        $this->assertSame('Edited by the administrator', $translation->fresh()->title);
        $this->assertSame(874, Translation::where('published', true)->count());
        foreach (['/kk/', '/en/', '/kk/contacts/', '/en/contacts/', '/kk/privacy/', '/en/rental-conditions/', '/kk/cars/astana/', '/en/faq/'] as $path) {
            $response = $this->get($path)->assertOk()->assertSee('hreflang="kk"', false)->assertSee('hreflang="en"', false);
            if (! str_contains($path, '/privacy/')) {
                $response->assertSee('content="index,follow"', false);
            }
            $response->assertDontSee('This page is being translated.')->assertDontSee('Бұл беттің аудармасы дайындалуда.');
        }
        $this->get('/en/')->assertSee('Self-drive car rental in Astana')->assertSee('Car rental in Astana')->assertDontSee('Прокат авто');
        $this->get('/kk/')->assertSee('Астанада жүргізушісіз');
        $xml = simplexml_load_string($this->get('/sitemap-cars.xml')->assertOk()->getContent());
        $this->assertCount(273, $xml->url);
        $pages = simplexml_load_string($this->get('/sitemap-pages.xml')->assertOk()->getContent());
        $paths = array_map('strval', iterator_to_array($pages->xpath('//loc')));
        $this->assertCount(count(array_unique($paths)), $paths);
        $this->get('/sitemap-pages.xml')->assertDontSee('/booking/')->assertDontSee('/callback/')->assertDontSee('/request-success/');
    }

    public function test_published_translation_removed_from_alternates_and_sitemap_when_incomplete(): void
    {
        $this->publish();
        $car = Car::public()->first();
        $translation = $car->translations()->where('language', 'en')->first();
        $translation->published = false;
        $translation->save();
        $this->get($car->legacy_path)->assertDontSee('hreflang="en"', false)->assertSee('hreflang="kk"', false);
        $this->get('/en'.$car->legacy_path)->assertSee('content="noindex,follow"', false);
        $this->get('/sitemap-cars.xml')->assertDontSee('/en'.$car->legacy_path.'</loc>', false);
        $this->get('/en/cars/astana/')->assertSee('content="noindex,follow"', false)->assertDontSee('hreflang="en"', false);
        $this->get('/sitemap-pages.xml')->assertDontSee('https://legionautorent.kz/en/cars/</loc>', false);
        $faq = FAQ::where('active', true)->first();
        $faq->translations()->where('language', 'en')->first()->update(['published' => false]);
        $this->get('/en/faq/')->assertSee('content="noindex,follow"', false)->assertDontSee('hreflang="en"', false);
        $this->get('/sitemap-pages.xml')->assertDontSee('https://legionautorent.kz/en/faq/</loc>', false);
    }

    public function test_home_links_and_language_switch_keep_the_selected_city(): void
    {
        $this->publish();
        $this->get('/kostanay/')->assertOk();
        $this->get('/contacts/')->assertSee('data-city-home href="/kostanay/"', false);
        $catalogue = $this->get('/cars/kostanay/')->assertOk();
        $this->assertSame(14, substr_count($catalogue->getContent(), 'class="car-card"'));
        $catalogue->assertDontSee('name="city"', false);
        $this->get('/cars/?city=')->assertRedirect('/cars/');
        $this->get('/cars/')->assertRedirect('/cars/astana/');
        $this->get('/kostanay/')->assertOk();
        $this->get('/')->assertOk()->assertSee('data-city-home href="/"', false);
        $this->get('/en/')->assertOk();
        $this->get('/en/kostanay/')->assertOk()->assertSee('Self-drive car rental in Kostanay');
        $this->get('/en/contacts/')->assertSee('data-city-home href="/en/kostanay/"', false)->assertSee('data-city-switch="kostanay"', false);
        $this->get('/')->assertOk()->assertSee('data-city-home href="/"', false);
    }

    public function test_filter_has_only_city_price_and_sorting_and_old_urls_still_work(): void
    {
        $this->get('/cars/astana/')->assertOk()->assertDontSee('name="city"', false)->assertSee('name="min_price"', false)->assertSee('name="max_price"', false)->assertSee('name="sort"', false)->assertDontSee('name="brand"', false)->assertDontSee('name="category"', false)->assertDontSee('name="seats"', false)->assertDontSee('name="transmission"', false)->assertDontSee('name="drive"', false)->assertDontSee('name="start_date"', false)->assertDontSee('class="category-chip', false);
        $this->get('/')->assertOk()->assertDontSee('name="start_date"', false)->assertDontSee('quick-category');
        $this->get('/category/economy/')->assertOk();
    }

    public function test_system_page_seo_can_be_edited_and_is_used_by_the_page(): void
    {
        $this->publish();
        $page = Page::where('path', '/cars/astana/')->firstOrFail();
        $page->update(['seo_title' => 'Настроенный каталог', 'seo_description' => 'Описание каталога администратором', 'seo_h1' => 'Автомобили для вашего маршрута', 'og_title' => 'Каталог для социальных сетей']);
        $this->get('/cars/astana/')->assertSee('<title>Настроенный каталог</title>', false)->assertSee('Описание каталога администратором')->assertSee('Автомобили для вашего маршрута')->assertSee('property="og:title" content="Каталог для социальных сетей"', false);
    }

    public function test_ajax_request_reports_errors_and_success_without_a_redirect(): void
    {
        $this->publish();
        $car = Car::public()->where('accepts_requests', true)->first();
        $city = $car->cities->first();
        $this->postJson('/en/booking/', ['city' => $city->id, 'car' => $car->id, 'name' => 'Test', 'phone' => '123', 'consent' => 'on', 'source_token' => BookingController::token($car->legacy_path)])->assertStatus(400)->assertJsonPath('success', false)->assertJsonPath('errors.phone.0', 'Enter a phone number with 10 to 15 digits.');
        $response = $this->postJson('/en/booking/', ['city' => $city->id, 'car' => $car->id, 'name' => 'Test', 'phone' => '+77001112233', 'consent' => 'on', 'source_token' => BookingController::token($car->legacy_path)])->assertOk()->assertJsonPath('success', true)->assertJsonPath('message', 'Thank you! Your request has been sent. Our manager will contact you to confirm the details.');
        $this->assertNull($response->headers->get('Location'));
        $lead = BookingRequest::firstOrFail();
        $this->assertSame('/en'.$car->legacy_path, $lead->source_page);
        $this->assertSame($city->id, $lead->city_id);
        $this->postJson('/kk/callback/', ['city' => $city->id, 'name' => 'Тест', 'phone' => '+77001112233', 'source_token' => BookingController::token('/contacts/')])->assertStatus(400)->assertJsonPath('success', false)->assertJsonStructure(['errors' => ['consent']]);
    }
}
