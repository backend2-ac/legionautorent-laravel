@php($aboutCities = $nav_cities ?? collect())
<section class="section about-section" id="about" aria-labelledby="about-title">
    <div class="container about-layout">
        <div class="about-copy">
            <p class="eyebrow">{{ site_text('О компании') }} / <span class="brand-name">LEGIONAUTORENT</span></p>
            <h2 id="about-title">{{ site_text('8 лет в прокате автомобилей') }}</h2>
            <p>{{ site_text('LEGIONAUTORENT — прокат автомобилей без водителя с филиалами в городах Казахстана. Работаем круглосуточно, без выходных, и привозим автомобиль туда, где он вам нужен.') }}</p>
            <a class="text-link" href="{{ language_url('/about/') }}">{{ site_text('Подробнее о компании') }} ↗</a>
        </div>
        <dl class="about-facts">
            <div><dt>8</dt><dd>{{ site_text('лет на рынке проката') }}</dd></div>
            <div><dt>{{ $aboutCities->count() }}</dt><dd>{{ site_text('города с филиалами') }}: {{ $aboutCities->map(fn ($city) => localized($city, 'name'))->implode(', ') }}</dd></div>
            <div><dt>24/7</dt><dd>{{ site_text('без выходных и праздников') }}</dd></div>
            <div><dt>KZ</dt><dd>{{ site_text('выезд в другие города Казахстана по договорённости') }}</dd></div>
        </dl>
    </div>
</section>
