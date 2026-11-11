@if (isset($reels) && $reels->isNotEmpty())
<section class="section reviews-section" id="reviews" aria-labelledby="reviews-title" data-reels>
    <div class="container">
        <div class="reviews-heading">
            <div>
                <p class="eyebrow">{{ site_text('Видеоотзывы') }} / <span class="brand-name">LEGIONAUTORENT</span></p>
                <h2 id="reviews-title">{{ site_text('Клиенты о поездках с нами') }}</h2>
            </div>
            @if (filled($site->instagram ?? null))
                <a class="text-link reviews-account" href="{{ $site->instagram }}" target="_blank" rel="noopener noreferrer" data-event="click_instagram">{{ icon('instagram') }} {{ site_text('Все видео в Instagram') }} ↗</a>
            @endif
        </div>
        <div class="reels-track" role="list">
            @foreach ($reels as $reel)
                <article class="reel-card" role="listitem">
                    <div class="reel-frame" data-reel="{{ $reel->text }}">
                        <button class="reel-poster" type="button" data-reel-play aria-label="{{ site_text('Смотреть видеоотзыв') }}: {{ localized($reel, 'title') }}">
                            <span class="reel-play" aria-hidden="true"></span>
                            <span class="reel-poster-label">{{ site_text('Смотреть видеоотзыв') }}</span>
                        </button>
                        <blockquote class="instagram-media" data-instgrm-permalink="{{ $reel->text }}" data-instgrm-version="14" hidden>
                            <a href="{{ $reel->text }}" target="_blank" rel="noopener noreferrer">{{ site_text('Открыть в Instagram') }}</a>
                        </blockquote>
                    </div>
                    @if (filled(localized($reel, 'title')))
                        <p class="reel-caption">{{ localized($reel, 'title') }}</p>
                    @endif
                </article>
            @endforeach
        </div>
    </div>
</section>
@endif
