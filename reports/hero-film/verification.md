# Current desktop layout correction — 2026-10-15

The latest user review supersedes the fullscreen alternatives described below. Desktop now has 40 px outer gutters; the complete source frame fits below the header beside the copy. Canvas uses contain on desktop, mobile retains cover. Chapter captions and their numbers were removed from the visible HTML. Both old review hashes open this corrected layout.

Verified full-frame drawing bounds, positive image size, separation from the copy/header, keyboard booking and no horizontal overflow at 768×800, 882×804, 1440×900, 1920×1080 and 1366×600. See desktop-fit.json and desktop-fit-*.png. The hero PHP checks pass (3 tests, 552 assertions). The general browser script was updated for the removal of captions. The Lighthouse and fullscreen-*.png results below are the earlier fullscreen baseline, not fresh measurements of this layout.

## Initial fullscreen implementation and measurements

# Hero film: implementation and verification

Date: 2026-10-15. Branch: `codex/hero-film-chapters`.

## Review links

- Default / selected treatment: http://127.0.0.1:8004/#hero-cover — full viewport, cropped vertical footage.
- Alternative: http://127.0.0.1:8004/#hero-contain — complete frame with blurred continuation.
- Both treatments use cover below 768 px, as requested. Hash fragments only select a presentation; there are no new server routes.

## Result

Seven scroll-linked chapters, 122 WebP frames per variant, 10 fps source sampling, four-frame baked dissolves, final fade to black. H1 and booking link remain fixed during the sequence. City selector remains visible. Dealer-ad scene 9.4–11.3 s is excluded; remaining plate advertisements are masked, and the grille scene is cropped. Day scenes receive mild cooler/darker grading. Source audio is omitted with `-an`.

The first image is preloaded. No sequence frames are requested until scrolling begins. The loader then fetches the current/next chapter in batches of three, decodes the nearby window, releases ImageBitmaps, pauses when hidden/offscreen, caps DPR at 2 and reuses downloaded blobs after resize. No-JS, failed-frame and reduced-motion views retain a static image. Reduced motion disables sticky scrolling.

Selected cover treatment was improved after visual review: desktop WebP quality 75 → 80, poster 540×960 → 720×1280, high-quality canvas scaling. These reduce avoidable losses but cannot recover detail absent from the 720×1280 compressed source. A high-resolution original, preferably composed for landscape, is needed for a genuinely sharp wide-screen result. Cover crops and enlarges the vertical source.

## Measured budgets

| Asset | Result | Limit |
| --- | ---: | ---: |
| Desktop 720×1280, quality 80 | 4,412,532 bytes | 5,000,000 |
| Mobile 540×960, quality 75 | 2,467,636 bytes | 3,000,000 |
| Poster 720×1280 | 52,746 bytes | 60,000 |
| Hero JS, gzip | 2,271 bytes | 15,000 |

## Checks

Local Docker production configuration, Chrome mobile Lighthouse simulation:

- Performance: 95/100.
- Accessibility: 100/100.
- Best practices: 100/100.
- LCP: 2.3 s.
- CLS: 0.
- TBT during page load: 150 ms.

See `lighthouse-mobile.report.html` and `.json`. These are local lab measurements, not production real-user data. SEO category intentionally excluded: this task leaves indexability, URLs and metadata unchanged.

PHP: 23 feature tests / 1398 assertions passed (hero, navigation, breadcrumbs, admin workflows). After the final image-quality change, the 3 hero tests / 552 assertions passed again. Pint and Vite build pass.

Browser checks: Chrome at 360, 768 and 1440 px; all seven chapters, reverse scrolling, fixed H1, city visibility, no horizontal overflow, keyboard booking link, no JS, reduced motion, unavailable createImageBitmap, failed frame requests. The six-second warmed scroll sampled 120 distinct frames and no >50 ms long tasks on this machine. First screen requested zero sequence frames. WebKit and Firefox at 390×844 checked middle/final chapters and reduced-motion changes. Both fullscreen modes checked at 1440×900. Screenshots and JSON results are alongside this report. Physical iPhone/Android devices were not tested; emulation does not establish universal device support.

## Rebuild locally

Requires PHP GD/WebP, local ffmpeg and existing Node dependencies. Source files stay under `resources/hero-source/`.

```sh
php artisan hero:build-frames --ffmpeg=/absolute/path/to/ffmpeg --preview --no-interaction
php artisan hero:build-frames --ffmpeg=/absolute/path/to/ffmpeg --no-interaction
npm run build
```

The preview produces `reports/hero-film/contact-sheet.jpg`. Full build publishes revisioned frames plus `public/static/hero-film/manifest.json`. Commit the frames, manifest and compiled Vite assets together. No production frame generation or new runtime dependency is needed. The original MP4 is excluded from Docker runtime images; pre-existing unrelated ffmpeg/ffprobe support in the image is unchanged.

## Add another source, for example Lexus

1. Place the source in `resources/hero-source/lexus.mp4`.
2. Add or replace scene entries in `resources/hero-source/scenes.json`, with `file`, `start`, `end` and `caption`. Captions use Russian keys; add KK/EN translations in `resources/data/interface-translations.json`.
3. Optional fields: `daylight`, `crop: [width,height,x,y]`, `masks: [[x,y,width,height]]` (expressions use seconds relative to scene start), `fade_out` in seconds.
4. Generate and inspect the contact sheet, then the full sequence. Keep the duration within the byte budgets; the command lowers quality if necessary and fails rather than silently exceeding a budget.
5. Rebuild frontend assets and commit the generated revision. No JavaScript chapter-count edits are needed. Update fixture expectations in the tests if the chapter count changes.

Kazakh captions require review by a fluent human before release; this note is also in the source manifest and is not shown to visitors.

## Main changed files

- `app/Console/Commands/BuildHeroFrames.php`: deterministic local frame generation, dissolves, masks, preview and budgets.
- `app/Services/Hero.php`, `resources/views/home.blade.php`, `resources/views/components/hero_film.blade.php`: manifest context, preloaded poster and accessible HTML.
- `frontend/hero-film.js`, `frontend/hero-film.css`, `frontend/main.js`: lazy scroll playback and two fullscreen treatments.
- `frontend/ambient-motion.js`, `frontend/ambient-motion.css`, `frontend/refinements.css`: remove obsolete hero hooks.
- `resources/hero-source/scenes.json`, `resources/hero-source/mercedes-brabus.mp4`: editable source scenes and original footage.
- `resources/data/interface-translations.json`: RU-keyed KK/EN chapter translations.
- `public/static/hero-film/`, `public/static/build/`: committed generated assets.
- `.dockerignore`: exclude original MP4 from runtime image.
- `tests/Feature/HeroFilmTest.php`, `HeroCompatibilityTest.php`, `HeroPreviewTest.php`, `FullscreenHeroAndNavigationTest.php`: new hero checks while preserving navigation/admin/breadcrumb coverage.
- `tests/browser/hero_film*.py`: browser verification; `reports/hero-film/`: evidence and this report.
- Removed obsolete hero component, JS/worker/CSS, old frame-builder commands, old sequence packs and old hero MP4. Existing small SEO fallback posters remain because metadata still uses them.

Browser reproduction (Python with Playwright and installed engines):

```sh
python tests/browser/hero_film.py
python tests/browser/hero_film_variants.py
python tests/browser/hero_film_cross_browser.py
```

Optional environment: `HERO_BASE_URL`, `HERO_REPORT`, `BROWSER_EXECUTABLE` for Chrome, and `PLAYWRIGHT_BROWSERS_PATH` for engine installations.
