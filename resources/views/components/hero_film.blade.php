<section class="hero-film" data-hero-film data-fit="cover" style="--chapters:{{ count($hero_film['chapters']) }}" aria-label="{{ site_text('Mercedes-Brabus: экстерьер и детали') }}">
  {{ df($hero_film['sequence'], 'json_script', 'hero-film-manifest') }}
  <script nonce="{{ $request->csp_nonce }}">(function(){var root=document.currentScript.parentElement;root.dataset.fit=location.hash==='#hero-contain'?'contain':'cover';root.dataset.motion=String(!!JSON.parse(root.querySelector('#hero-film-manifest').textContent)&&'fetch' in window&&'IntersectionObserver' in window&&!matchMedia('(prefers-reduced-motion: reduce)').matches);})();</script>
  <div class="hero-film__stage">
    <canvas class="hero-film__background" style="background: url('{{ $hero_film['poster'] }}') center / cover" width="64" height="114" aria-hidden="true"></canvas>
    <div class="hero-film__layout container">
      <div class="hero-film__copy">
        <p class="hero-film__city">{{ df($city, 'tr', 'name') }}</p>
        <h1>{{ $seo['h1'] }}</h1>
        <ol class="hero-film__captions">
          @foreach($hero_film['chapters'] as $chapter)
            <li class="{{ $loop->first ? 'is-current' : '' }}" data-film-caption><span aria-hidden="true">{{ sprintf('%02d', $loop->iteration) }}</span> {{ site_text($chapter['caption']) }}</li>
          @endforeach
        </ol>
        <a class="button hero-film__book" href="#fleet">{{ site_text('Забронировать') }} <span aria-hidden="true">↗</span></a>
        <p class="hero-film__hint">{{ site_text('Листайте') }} <span aria-hidden="true">↓</span></p>
      </div>
      <div class="hero-film__picture">
        <img src="{{ $hero_film['poster'] }}" width="720" height="1280" alt="{{ $hero_film['alt'] }}" fetchpriority="high" decoding="async">
        <canvas class="hero-film__canvas" width="720" height="1280" aria-hidden="true"></canvas>
      </div>
      <div class="hero-film__dots" aria-hidden="true">
        @foreach($hero_film['chapters'] as $chapter)<span class="{{ $loop->first ? 'is-current' : '' }}"></span>@endforeach
      </div>
    </div>
  </div>
</section>
@include('components.search_bar')
