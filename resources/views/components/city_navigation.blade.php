<nav class="city-navigation" aria-label="{{ site_text('Города') }}">
    @foreach($nav_cities as $nav_city)
        <a data-city-switch="{{ $nav_city->slug }}" href="{{ city_switch_url($nav_city) }}" @if($request->selected_city?->id === $nav_city->id) aria-current="location" @endif>{{ localized($nav_city, 'name') }}</a>
    @endforeach
</nav>
