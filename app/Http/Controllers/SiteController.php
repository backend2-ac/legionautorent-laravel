<?php

namespace App\Http\Controllers;

use App\Models\Car;
use App\Models\CarCategory;
use App\Models\City;
use App\Models\ContentBlock;
use App\Models\FAQ;
use App\Models\Page;
use App\Models\Redirect;
use App\Services\Hero;
use App\Services\PublicContent;
use App\Services\Seo;
use App\Support\PublicForm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SiteController extends Controller
{
    public static function cars(): Builder
    {
        return Car::public()->with(['brand', 'category.translations', 'cities.translations', 'translations', 'images.translations', 'discounts'])->orderByDesc('featured')->orderBy('sort_order')->orderBy('id');
    }

    public static function blocks(string $kind): Collection
    {
        return ContentBlock::where('active', true)->where('kind', $kind)->with('translations')->orderBy('sort_order')->orderBy('id')->get();
    }

    public static function faqs(): Builder
    {
        return FAQ::where('active', true)->with('translations')->orderBy('sort_order')->orderBy('id');
    }

    public static function render(string $view, array $ctx, int $status = 200): Response
    {
        $r = request();
        $public = PublicContent::all();
        $site = clone $public['site'];
        if (config('legion.gtm')) {
            $site->gtm_id = config('legion.gtm');
        }if (config('legion.whatsapp')) {
            $site->whatsapp = config('legion.whatsapp');
        }
        $cities = $r->attributes->get('nav_cities') ?? $public['cities'];
        $selected = $r->attributes->get('selected_city') ?? $cities->firstWhere('legacy_path', $r->attributes->get('base_path'));
        $menu = $public['menu']->reject(fn ($item) => rtrim($item->path, '/') === '/cars');
        $alias = (object) ['path' => $r->getPathInfo(), 'selected_city' => $selected, 'main_menu' => $menu->where('area', 'main'), 'mobile_menu' => $menu->where('area', 'mobile'), 'home_sections' => $public['sections'], 'csp_nonce' => $r->attributes->get('nonce')];
        $base = ['request' => $alias, 'site' => $site, 'nav_cities' => $cities, 'nav_categories' => $public['categories'], 'footer_pages' => $public['pages'], 'hero_film' => Hero::context($site), 'is_staging' => config('legion.staging'), 'analytics_enabled' => config('legion.analytics') && ! config('legion.staging'), 'current_language' => app()->getLocale(), 'language_links' => collect(['ru' => 'RU', 'kk' => 'KZ', 'en' => 'EN'])->map(fn ($label, $l) => ['language' => $l, 'label' => $label, 'url' => language_url($r->attributes->get('base_path'), $l)])->values()->all(), 'partner_translated' => app()->getLocale() === 'ru' || $site->translations->contains(fn ($t) => $t->published && $t->language === app()->getLocale()), 'city' => null, 'cars' => collect(), 'category' => null, 'faqs' => collect(), 'breadcrumbs' => [], 'compact' => false, 'callback' => false, 'filter_form' => null, 'form' => null];
        $ctx = array_replace($base, $ctx);
        $ctx['schemas'] = Seo::schemas($ctx['seo'], $site, $ctx);

        return response()->view($view, $ctx, $status);
    }

    public function __invoke(Request $r): \Symfony\Component\HttpFoundation\Response
    {
        $path = $r->attributes->get('base_path', '/');
        $cities = $r->attributes->get('nav_cities');
        $landing = $cities?->firstWhere('legacy_path', $path);
        $catalogCity = $r->attributes->get('catalog_city');
        $isCatalog = $path === '/cars/' || $catalogCity;
        if ($r->query->has('city') && ($landing || $isCatalog)) {
            $explicitCity = $cities?->firstWhere('slug', $r->query('city'));
            if ($explicitCity || blank($r->query('city'))) {
                $target = $landing ? ($explicitCity ?? $landing)->legacy_path : ($explicitCity?->getCatalogPath() ?? '/cars/');
                $query = $r->except('city');

                return new RedirectResponse(language_url($target).($query ? '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986) : ''), 301);
            }
        }
        if ($path === '/cars/') {
            $city = $r->attributes->get('selected_city') ?? $cities?->firstWhere('legacy_path', '/') ?? $cities?->first();
            abort_unless($city, 404);
            $query = $r->except('city', 'page');

            return new RedirectResponse(language_url($city->getCatalogPath()).($query ? '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986) : ''), 302);
        }
        if (str_starts_with($path, '/kz/')) {
            return $this->missing();
        }
        if (str_starts_with($path, '/cars/') && $path !== '/cars/') {
            if (! $catalogCity || Page::where('path', $path)->where('active', false)->exists()) {
                return $this->missing();
            }
        }
        if ($isCatalog || preg_match('#^/category/([a-zA-Z0-9_-]+)/$#', $path, $match)) {
            return $this->catalog($r, $match[1] ?? null);
        }
        if ($path === '/faq/') {
            return self::render('faq_page', ['seo' => Seo::page(title: site_text('FAQ | LEGIONAUTORENT'), description: site_text('Ответы на вопросы об аренде автомобилей.'), h1: site_text('Вопросы об аренде'), languages: Seo::faqLanguages()), 'faqs' => self::faqs()->whereNull('car_id')->whereNull('page_id')->get()]);
        }
        if ($path === '/request-success/') {
            return self::render('success', ['seo' => Seo::page(title: site_text('Заявка отправлена | LEGIONAUTORENT'), h1: site_text('Заявка отправлена'), noindex: true), 'submitted' => (bool) $r->session()->pull('booking_success')]);
        }
        if ($path === '/booking/' || $path === '/callback/') {
            return app(BookingController::class)->show($r, $path === '/callback/');
        }
        if ($city = City::where('active', true)->where('legacy_path', $path)->with('translations')->first()) {
            $cars = self::cars()->whereHas('cities', fn ($q) => $q->where('locations_city.id', $city->id))->get();
            $ctx = ['seo' => Seo::page($city), 'city' => $city, 'cars' => $cars, 'car_count' => $cars->count(), 'min_price' => $cars->min('base_price'), 'steps' => self::blocks('step'), 'benefits' => self::blocks('benefit'), 'faqs' => self::faqs()->whereNull('car_id')->whereNull('page_id')->where(fn ($q) => $q->whereNull('city_id')->orWhere('city_id', $city->id))->get(), 'breadcrumbs' => $path === '/' ? [] : [[site_text('Главная').' — '.localized($city, 'name'), language_url($path)]]];

            return self::render($path === '/' ? 'home' : 'city', $ctx);
        }
        if ($car = self::cars()->where('legacy_path', $path)->with(['prices.translations', 'extra_specs.translations'])->first()) {
            $selected = $r->attributes->get('selected_city');
            $city = $selected && $car->cities->contains('id', $selected->id) ? $selected : $car->cities->firstWhere('active', true);
            $ctx = ['car' => $car, 'city' => $city, 'seo' => Seo::page($car), 'related_cars' => self::cars()->when($car->category_id, fn ($query) => $query->where('category_id', $car->category_id))->where('id', '!=', $car->id)->when($city, fn ($query) => $query->whereHas('cities', fn ($cities) => $cities->where('locations_city.id', $city->id)))->limit(3)->get(), 'faqs' => self::faqs()->where('car_id', $car->id)->get(), 'conditions' => self::blocks('condition'), 'breadcrumbs' => [[site_text('Главная'), home_url()], [site_text('Автопарк'), catalog_url($city)], [localized($car, 'name'), language_url($path)]], 'booking_form' => new PublicForm('booking', ['city' => $city?->id, 'car' => $car->id, 'source_token' => BookingController::token($path)]), 'car_whatsapp_message' => str_replace('%(car)s', localized($car, 'name'), site_text('Здравствуйте! Интересует аренда %(car)s.'))];

            return self::render('car_detail', $ctx);
        }
        if ($page = Page::where('active', true)->where('path', $path)->with('translations')->first()) {
            $ctx = ['page' => $page, 'seo' => Seo::page($page), 'faqs' => self::faqs()->where('page_id', $page->id)->get(), 'conditions' => self::blocks('condition'), 'breadcrumbs' => [[site_text('Главная'), home_url()], [localized($page, 'title'), language_url($path)]]];
            if ($page->slug === 'contacts') {
                $ctx['city'] = $r->attributes->get('selected_city');
                $ctx['form'] = new PublicForm('callback', ['city' => $ctx['city']?->id, 'source_token' => BookingController::token($path)]);
            }

            return self::render('page', $ctx);
        }
        if ($red = Redirect::where('active', true)->where('old_path', $r->getPathInfo())->first()) {
            return redirect($red->new_path, $red->status_code);
        }

        return $this->missing();
    }

    public function missing(): Response
    {
        return self::render('404', ['seo' => Seo::page(title: site_text('Страница не найдена | LEGIONAUTORENT'), h1: site_text('Страница не найдена'), noindex: true)], 404);
    }

    public function catalog(Request $r, ?string $slug = null): Response
    {
        $cat = $slug ? CarCategory::where('active', true)->where('slug', $slug)->with('translations')->first() : null;
        if ($slug && ! $cat) {
            return $this->missing();
        }$q = self::cars();
        $catalogCity = $r->attributes->get('catalog_city');
        $cityFilter = $r->query->has('city') ? $r->query('city') : $catalogCity?->slug;
        if ($cat) {
            $q->where('category_id', $cat->id);
        }$rules = ['city' => 'nullable|exists:locations_city,slug', 'category' => 'nullable|exists:cars_carcategory,slug', 'brand' => 'nullable|exists:cars_carbrand,slug', 'min_price' => 'nullable|integer|min:0', 'max_price' => 'nullable|integer|min:0', 'transmission' => 'nullable|in:automatic,manual', 'drive' => 'nullable|in:front,rear,all', 'seats' => 'nullable|integer|between:1,20', 'q' => 'nullable|string|max:100', 'sort' => 'nullable|in:price,-price,new', 'start_date' => 'nullable|date', 'end_date' => 'nullable|date|after:start_date'];
        $validator = validator($r->query(), $rules);
        $errors = $validator->errors()->toArray();
        if ($r->filled('min_price') && $r->filled('max_price') && $r->min_price > $r->max_price) {
            $errors['max_price'] = [site_text('Минимальная цена не может быть выше максимальной.')];
        }
        if ($errors) {
            $q->whereRaw('1=0');
        } else {
            if (filled($cityFilter)) {
                $q->whereHas('cities', fn ($c) => $c->where('slug', $cityFilter));
            }if ($r->filled('brand')) {
                $q->whereHas('brand', fn ($c) => $c->where('slug', $r->brand));
            }if ($r->filled('category')) {
                $q->whereHas('category', fn ($c) => $c->where('slug', $r->category));
            }
            foreach (['min_price' => ['base_price', '>='], 'max_price' => ['base_price', '<='], 'seats' => ['seats', '>='], 'transmission' => ['transmission', '='], 'drive' => ['drive', '=']] as $key => [$col,$op]) {
                if ($r->filled($key)) {
                    $q->where($col, $op, $r->$key);
                }
            }if ($r->boolean('available')) {
                $q->where('accepts_requests', true);
            }if ($r->filled('q')) {
                $q->where(fn ($c) => $c->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($r->q).'%'])->orWhereRaw('LOWER(model_name) LIKE ?', ['%'.mb_strtolower($r->q).'%'])->orWhereHas('brand', fn ($b) => $b->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($r->q).'%'])));
            }
            if (in_array($r->sort, ['price', '-price', 'new'])) {
                $q->reorder()->orderBy($r->sort === 'new' ? 'created_at' : 'base_price', $r->sort === 'price' ? 'asc' : 'desc')->orderBy('id');
            }
        }$cars = $q->get();
        $ctx = ['seo' => Seo::page($cat, title: site_text('Автопарк | LEGIONAUTORENT'), description: site_text('Выберите автомобиль для аренды без водителя. Цены, фотографии и классы автомобилей в LEGIONAUTORENT.'), h1: site_text('Ваш маршрут. Ваш автомобиль.'), noindex: collect($r->only(array_keys($rules)))->contains(fn ($value) => filled($value)), languages: $cat ? null : Seo::catalogLanguages()), 'city' => $catalogCity, 'category' => $cat, 'cars' => $cars, 'car_count' => $cars->count(), 'filter_form' => new PublicForm('filter', array_replace(['city' => $cityFilter], $r->query()), $errors), 'breadcrumbs' => [[site_text('Главная'), home_url()], [site_text('Автопарк'), catalog_url()]]];
        if ($r->header('X-Legion-Partial') === 'catalog') {
            return self::render('components.catalog_results', $ctx)->header('X-Legion-Selected-City', $r->attributes->get('selected_city')?->slug ?? '')->header('X-Legion-Home', home_url())->header('X-Legion-Robots', $ctx['seo']['robots']);
        }

        return self::render('catalog', $ctx);
    }
}
