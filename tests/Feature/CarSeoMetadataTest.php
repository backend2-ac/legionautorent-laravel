<?php

namespace Tests\Feature;

use App\Models\Car;
use Tests\CatalogueTestCase;

class CarSeoMetadataTest extends CatalogueTestCase
{
    public function test_imported_car_titles_gain_the_city_and_the_from_price(): void
    {
        $car = Car::public()->with('cities')->first();
        $car->update(['seo_title' => $car->name, 'seo_description' => 'Аренда '.$car->name.' без водителя от 1 ₸ в сутки.']);
        $city = $car->fresh()->cities->firstWhere('active', true);
        $price = number_format($car->fresh()->fromPrice(), 0, '.', ' ');

        $html = $this->get($car->legacy_path)->assertOk()->getContent();

        $this->assertStringContainsString('<title>Аренда '.e($car->name).' в '.e($city->name_in).' — от '.$price.' ₸/сутки | LEGIONAUTORENT</title>', $html);
        $this->assertStringContainsString('без водителя в '.e($city->name_in).' от '.$price.' ₸ в сутки', $html);
        $this->assertStringContainsString('property="og:title" content="Аренда '.e($car->name), $html);
    }

    public function test_seo_copy_written_in_the_admin_is_kept(): void
    {
        $car = Car::public()->first();
        $car->update(['seo_title' => 'Особый заголовок для этой машины', 'seo_description' => 'Особое описание.']);

        $this->get($car->legacy_path)->assertOk()
            ->assertSee('<title>Особый заголовок для этой машины</title>', false)
            ->assertSee('content="Особое описание."', false);
    }
}
