<?php

namespace Tests\Feature;

use App\Models\Car;
use App\Models\City;
use App\Models\Translation;
use App\Services\Seo;
use Illuminate\Support\Facades\DB;
use Tests\CatalogueTestCase;

class MigrationParityTest extends CatalogueTestCase
{
    public function test_import_preserves_all_public_records_and_relations(): void
    {
        $snapshot = json_decode(file_get_contents(resource_path('data/catalogue-snapshot.json')), true);
        foreach ($snapshot['tables'] as $table => $rows) {
            $this->assertSame(count($rows), DB::table($table)->count(), $table);
        }
        foreach (['cars_car', 'locations_city', 'pages_page'] as $table) {
            foreach ($snapshot['tables'][$table] as $row) {
                $saved = (array) DB::table($table)->find($row['id']);
                foreach (['slug', 'legacy_path', 'path', 'seo_title', 'seo_description', 'seo_h1', 'canonical_url', 'name', 'title', 'body', 'description', 'base_price'] as $field) {
                    if (array_key_exists($field, $row)) {
                        $this->assertSame((string) $row[$field], (string) $saved[$field], $table.' '.$row['id'].' '.$field);
                    }
                }
            }
        }
        $this->assertSame(91, Car::public()->count());
        $this->assertSame(101, DB::table('cars_car_cities')->count());
        $this->assertSame(302, DB::table('cars_carimage')->count());
    }

    public function test_all_95_exact_legacy_urls_serve_200_and_preserve_seo(): void
    {
        $objects = City::where('active', true)->get()->concat(Car::public()->get());
        $this->assertCount(95, $objects);
        foreach ($objects as $object) {
            $response = $this->get($object->legacy_path);
            [$title, $description] = $object instanceof Car
                ? (Seo::carMeta($object, null, 'ru', (string) $object->seo_title, (string) $object->seo_description) ?? [$object->seo_title, $object->seo_description])
                : [$object->seo_title, $object->seo_description];
            $response->assertOk()->assertSee('<title>'.e($title).'</title>', false)->assertSee('content="'.e($description).'"', false)->assertSee('href="'.e($object->canonical_url ?: config('legion.site_url').$object->legacy_path).'"', false)->assertSee(e($object->seo_h1), false);
            $this->assertNull($response->headers->get('Location'));
        }
    }

    public function test_all_auxiliary_pages_and_locale_fallbacks_are_usable(): void
    {
        foreach (['/cars/astana/', '/contacts/', '/privacy/', '/consent/', '/rental-conditions/', '/faq/', '/kk/', '/en/', '/kk/cars/astana/', '/en/contacts/'] as $path) {
            $this->get($path)->assertOk();
        }
        $this->get('/kk/')->assertSee('content="noindex,follow"', false)->assertDontSee('hreflang="kk"', false);
        $this->get('/en/')->assertDontSee('hreflang="en"', false);
        foreach (['/booking/' => '/contacts/', '/callback/' => '/contacts/', '/kk/callback/' => '/kk/contacts/', '/request-success/' => '/'] as $old => $target) {
            $this->get($old)->assertRedirect($target)->assertStatus(301);
        }
        $this->get('/kz/')->assertNotFound();
        $this->get('/missing-legacy-page/')->assertNotFound();
        $retired = json_decode(file_get_contents(resource_path('data/legacy-500-paths.json')), true);
        foreach ($retired as $path) {
            $this->get($path)->assertNotFound();
        }
        (require database_path('migrations/2026_11_11_193753_redirect_retired_car_urls.php'))->up();
        foreach ($retired as $path) {
            $this->get($path)->assertStatus(301)->assertRedirect('/cars/astana/');
        }
        $this->get('/sitemap-cars.xml')->assertDontSee($retired[0]);
    }

    public function test_city_og_urls_sitemap_and_translation_gates_agree(): void
    {
        foreach (City::where('legacy_path', '!=', '/')->get() as $city) {
            $this->get($city->legacy_path)->assertSee('property="og:url" content="'.config('legion.site_url').$city->legacy_path.'"', false);
        }
        $sitemap = $this->get('/sitemap-cars.xml')->assertOk();
        $xml = simplexml_load_string($sitemap->getContent());
        $this->assertCount(91, $xml->url);
        $sitemap->assertDontSee('/kk/')->assertDontSee('/en/');
        $car = Car::public()->first();
        Translation::create(['content_type_id' => $car->contentTypeId(), 'object_id' => $car->id, 'language' => 'kk', 'published' => true, 'name' => 'Толық аударма', 'title' => 'Қазақша тақырып', 'description' => 'Қазақша сипаттама', 'h1' => 'Қазақша H1', 'content' => '<p>Толық қазақша мәтін.</p>']);
        $this->get($car->legacy_path)->assertSee('hreflang="kk"', false);
        $this->get('/kk'.$car->legacy_path)->assertSee('Қазақша H1')->assertSee('content="index,follow"', false);
        $this->get('/sitemap-cars.xml')->assertSee('/kk'.$car->legacy_path);
    }

    public function test_city_selection_survives_navigation_without_changing_canonical(): void
    {
        $this->get('/kostanay/')->assertOk();
        $this->get('/contacts/')->assertSee('data-city-switch="kostanay" href="/cars/kostanay/"  aria-current="location"', false);
        $this->get('/')->assertOk()->assertSee('data-city-home href="/"', false);
        $this->get('/kostanay/')->assertSee('<link rel="canonical" href="https://legionautorent.kz/kostanay/">', false);
    }
}
