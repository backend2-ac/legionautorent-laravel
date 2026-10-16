<?php

namespace Tests\Feature;

use App\Models\Car;
use Illuminate\Support\Facades\DB;
use Tests\CatalogueTestCase;

class CatalogueInteractionTest extends CatalogueTestCase
{
    public function test_catalogue_filters_and_partial_responses_retain_server_rendered_cars(): void
    {
        $response = $this->get('/cars/kostanay/?sort=price')->assertOk();
        $count = Car::public()->whereHas('cities', fn ($q) => $q->where('slug', 'kostanay'))->count();
        $this->assertSame($count, substr_count($response->getContent(), 'class="car-card"'));
        $partial = $this->withHeader('X-Legion-Partial', 'catalog')->get('/cars/kostanay/')->assertOk();
        $partial->assertHeader('X-Legion-Selected-City', 'kostanay')->assertDontSee('<html', false);
        $this->assertSame($count, substr_count($partial->getContent(), 'class="car-card"'));
    }

    public function test_invalid_filter_ranges_show_an_error_without_server_failure(): void
    {
        $this->get('/cars/astana/?min_price=50000&max_price=20000')->assertOk()->assertSee('Проверьте параметры поиска.')->assertDontSee('class="car-card"', false);
    }

    public function test_car_children_have_original_cascade_rules(): void
    {
        $rules = DB::select("SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='cars_car' AND TABLE_NAME IN ('cars_carimage','cars_cardiscount','cars_car_cities')");
        $this->assertCount(3, $rules);
        foreach ($rules as $rule) {
            $this->assertSame('CASCADE', $rule->DELETE_RULE);
        }
    }

    public function test_catalogue_search_remains_case_insensitive_with_binary_mysql_indexes(): void
    {
        $lower = $this->get('/cars/astana/?q=toyota')->assertOk()->getContent();
        $upper = $this->get('/cars/astana/?q=TOYOTA')->assertOk()->getContent();
        $count = substr_count($lower, 'class="car-card"');
        $this->assertGreaterThan(0, $count);
        $this->assertSame($count, substr_count($upper, 'class="car-card"'));
    }
}
