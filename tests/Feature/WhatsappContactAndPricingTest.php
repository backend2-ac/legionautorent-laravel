<?php

namespace Tests\Feature;

use App\Models\BookingRequest;
use App\Models\Car;
use Illuminate\Validation\ValidationException;
use Tests\CatalogueTestCase;

class WhatsappContactAndPricingTest extends CatalogueTestCase
{
    public function test_public_pages_offer_whatsapp_instead_of_a_lead_form(): void
    {
        $car = Car::public()->with('cities')->first();
        foreach (['/', '/contacts/', $car->legacy_path, '/cars/astana/'] as $path) {
            $this->get($path)->assertOk()->assertDontSee('data-request-form', false)->assertDontSee('name="phone"', false)->assertSee('https://wa.me/', false);
        }
        $this->get('/contacts/')->assertSee('whatsapp-cta', false);
        $carPage = $this->get($car->legacy_path)->getContent();
        $this->assertMatchesRegularExpression('#href="https://wa\.me/\d+\?text=[^"]*'.preg_quote(rawurlencode($car->name), '#').'#', $carPage);
    }

    public function test_old_form_addresses_redirect_and_no_longer_accept_submissions(): void
    {
        $car = Car::public()->first();
        $this->get('/booking/?car='.$car->id)->assertStatus(301)->assertRedirect($car->legacy_path);
        $this->get('/en/booking/')->assertStatus(301)->assertRedirect('/en/contacts/');
        $this->get('/request-success/')->assertStatus(301);
        $this->post('/booking/', ['name' => 'Тест', 'phone' => '+77001112233'])->assertStatus(405);
        $this->post('/kk/callback/', ['name' => 'Тест', 'phone' => '+77001112233'])->assertStatus(405);
        $this->assertSame(0, BookingRequest::count());
    }

    public function test_from_price_is_the_daily_price_minus_the_largest_discount(): void
    {
        $car = Car::public()->first();
        $car->update(['base_price' => 250000, 'price_from' => null]);
        $car->discounts()->delete();
        $car->discounts()->create(['label' => '', 'min_days' => 3, 'max_days' => 6, 'percent' => 10]);
        $car->discounts()->create(['label' => '', 'min_days' => 7, 'max_days' => null, 'percent' => 30]);

        $this->assertSame(175000, $car->fresh()->fromPrice());
        $this->get($car->legacy_path)->assertSee('<small class="price-from">от</small> <strong>175 000 <span>₸</span></strong>', false);
        $this->get('/kk'.$car->legacy_path)->assertSee('<strong>175 000 <span>₸</span></strong> <small class="price-from">бастап</small>', false);
    }

    public function test_a_manual_from_price_overrides_the_calculation_and_is_validated(): void
    {
        $car = Car::public()->first();
        $car->update(['base_price' => 100000, 'price_from' => 80000]);
        $this->assertSame(80000, $car->fresh()->fromPrice());

        $this->expectException(ValidationException::class);
        $car->update(['price_from' => 120000]);
    }

    public function test_catalogue_price_sorting_follows_the_from_price(): void
    {
        $response = $this->get('/cars/astana/?sort=price')->assertOk();
        preg_match_all('/data-price="(\d+)"/', $response->getContent(), $matches);
        $prices = array_map('intval', $matches[1]);
        $this->assertNotEmpty($prices);
        $sorted = $prices;
        sort($sorted);
        $this->assertSame($sorted, $prices);
    }
}
