<?php

namespace Tests\Feature;

use App\Models\Car;
use Tests\CatalogueTestCase;

class CarPhotosAndHomeFleetTest extends CatalogueTestCase
{
    public function test_the_car_gallery_shows_uncropped_photos_so_the_logo_stays_visible(): void
    {
        $car = Car::public()->whereHas('images', fn ($images) => $images->whereNotNull('card_image')->whereColumn('card_image', '!=', 'image'))->firstOrFail();
        $photo = $car->images->first();

        $gallery = $this->gallery($this->get($car->legacy_path)->assertOk()->getContent());

        $this->assertStringContainsString('src="/media/'.$photo->image.'"', $gallery);
        $this->assertStringNotContainsString($photo->card_image, $gallery);
    }

    public function test_home_page_car_cards_show_uncropped_photos(): void
    {
        $car = Car::public()->with('images')->orderByDesc('base_price')->get()->first(fn (Car $car): bool => $car->main_image && $car->main_image->card_image && $car->main_image->card_image !== $car->main_image->image);
        $this->assertNotNull($car);

        $this->get('/')->assertOk()->assertSee('src="/media/'.$car->main_image->image.'"', false)->assertDontSee('src="/media/'.$car->main_image->card_image.'"', false);
    }

    public function test_the_home_page_lists_every_car_without_a_show_more_button(): void
    {
        $this->get('/')->assertOk()->assertSee('data-fleet data-fleet-all', false)->assertDontSee('data-show-more', false);
        $this->get('/cars/astana/')->assertOk()->assertSee('data-show-more', false);
    }

    private function gallery(string $page): string
    {
        $start = strpos($page, '<section class="gallery"');
        $this->assertNotFalse($start);

        return substr($page, $start, strpos($page, '</section>', $start) - $start);
    }
}
