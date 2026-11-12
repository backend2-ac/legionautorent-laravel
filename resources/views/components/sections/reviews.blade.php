@if (isset($reels) && $reels->isNotEmpty())
<section class="section reviews-section" id="reviews" aria-labelledby="reviews-title" data-reels>
    <div class="container">
        <div class="reviews-heading">
            <div>
                <p class="eyebrow">{{ site_text('Видео') }} / <span class="brand-name">LEGIONAUTORENT</span></p>
                <h2 id="reviews-title">{{ site_text('LEGIONAUTORENT в Instagram') }}</h2>
            </div>
            <div class="reviews-tools">
                @if (filled($site->instagram ?? null))
                    <a class="text-link reviews-account" href="{{ $site->instagram }}" target="_blank" rel="noopener noreferrer" data-event="click_instagram">{{ icon('instagram') }} {{ site_text('Все видео в Instagram') }} {{ icon('arrow') }}</a>
                @endif
                <div class="reels-nav" data-reels-nav hidden>
                    <button class="reels-nav-button reels-nav-prev" type="button" data-reels-prev aria-label="{{ site_text('Назад') }}" aria-controls="reels-track">{{ icon('chevron') }}</button>
                    <button class="reels-nav-button reels-nav-next" type="button" data-reels-next aria-label="{{ site_text('Далее') }}" aria-controls="reels-track">{{ icon('chevron') }}</button>
                </div>
            </div>
        </div>
        <div class="reels-track" id="reels-track" role="list" tabindex="0" aria-label="{{ site_text('LEGIONAUTORENT в Instagram') }}" data-reels-track>
            @foreach ($reels as $reel)
                <article class="reel-card" role="listitem">
                    <div class="reel-frame" data-reel="{{ $reel->text }}">
                        <button class="reel-poster" type="button" data-reel-play aria-label="{{ site_text('Смотреть видео') }}: {{ localized($reel, 'title') }}">
                            <span class="reel-play" aria-hidden="true"></span>
                            <span class="reel-poster-label">{{ site_text('Смотреть видео') }}</span>
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
