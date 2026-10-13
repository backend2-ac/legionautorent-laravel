# Деплой Laravel-версии на PS.kz

## 1. Проверить тариф и окружение

Нужны PHP **8.4** для сайта и CLI, MySQL **8.4**, Composer 2, доступ к консоли или выполнению Artisan-команд. Это требования конкретного composer.lock. Возможности выбранного тарифа PS.kz нужно проверить в панели; публичный сервер в рамках переноса ещё не настраивался.

```bash
php -v
php -m
composer --version
```

Если CLI использует другую версию PHP, применять путь к бинарнику PHP 8.4, указанный панелью. После установки зависимостей выполнить `composer check-platform-reqs --no-dev`. PHP-расширения перечислены в README. Для новых загрузок видео нужен доступный `ffprobe`; текущая готовая последовательность кадров работает без него.

## 2. Загрузить код и закрытые данные

Разместить проект в отдельной папке. Корень сайта в панели должен указывать **на папку `public`**, например `<путь_проекта>/public`. Нельзя выставлять корнем весь проект или перемещать index.php в его корень: `.env`, частные снимки и исходники должны оставаться вне доступа браузера.

Распаковать:

1. Архив кода Laravel в папку проекта.
2. Архив медиа так, чтобы получился `<путь_проекта>/public/media/`.
3. Частный снимок в `<путь_проекта>/storage/app/migration/django-snapshot.json`.

Снимок содержит заявки и хеши учётных записей. Передавать его по закрытому каналу и хранить вне public. Если есть только Git-копия, фотографии и частные данные нужно добавить отдельно. Пароли в архив кода не входят.

## 3. База и .env

Создать новую пустую базу и отдельного пользователя в панели PS.kz. Использовать кодировку `utf8mb4` и сортировку `utf8mb4_bin`. Если панель предлагает другую сортировку по умолчанию, выставить нужную для базы; таблицы миграции также задают `utf8mb4_bin`.

```bash
cp .env.production.example .env
```

Заполнить `APP_URL`, `SITE_URL`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`. `SITE_URL` — окончательный канонический адрес. Для настоящего домена HTTPS установить `SESSION_SECURE_COOKIE=true`.

Оставить `APP_ENV=production`, `APP_DEBUG=false`, `IS_STAGING=true`, `ANALYTICS_ENABLED=false` до окончательной проверки. Пароли `CHANGE_ME` заменить. `MYSQL_ROOT_PASSWORD` нужен только варианту Docker, а не обычному хостингу PS.kz.

## 4. Установка и импорт

Каталоги кеша/сессий/Blade включены в Git через .gitignore-маркеры. Перед Composer проверить их наличие и доступ PHP на запись в storage и bootstrap/cache.

Команды выполняются из корня проекта:

```bash
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
composer check-platform-reqs --no-dev
php artisan key:generate --force
php artisan migrate --force
php artisan legion:import-django storage/app/migration/django-snapshot.json --dry-run
php artisan legion:import-django storage/app/migration/django-snapshot.json
php artisan legion:publish-locales
php artisan optimize
php artisan filament:optimize
```

`legion:publish-locales` заполняет 866 полных переводов KK/EN, короткие интерфейсные надписи и SEO-записи каталога/FAQ/форм. Команда повторяемая: опубликованные редактором переводы не заменяются. Также исправляется исходная заглушка текста Павлодара, если она ещё не была отредактирована.

**Перед импортом не выполнять db:seed.** Импорт допускает только пустые целевые таблицы и не заменяет существующие данные. `key:generate` выполняется только при первичной установке. При последующих обновлениях сохранить APP_KEY: смена ключа аннулирует сессии и защищённые токены форм.

Создать каталоги `storage/framework/cache/data`, `storage/framework/sessions`, `storage/framework/views`, `storage/logs`, `bootstrap/cache`, `public/media`. Владелец/группа должны позволять PHP писать в storage, bootstrap/cache и public/media. Не использовать права 777. После Composer уже опубликованы ресурсы Filament; при необходимости повторить `php artisan filament:assets`.

Готовая сборка Vite включена в код. Для пересборки на машине с Node.js 22:

```bash
npm ci --ignore-scripts
npm run build
```

Node.js не нужен во время работы сайта. Фото загружаются в public/media; `storage:link` для них не требуется.

## 5. Веб-сервер, HTTPS, ограничения загрузки

Для Apache используется `public/.htaccess`, должно работать mod_rewrite. Для VPS с Nginx взять `deploy/nginx.conf`, заменить `server_name` и root на реальные значения, а `fastcgi_pass app:9000` — на сокет/порт PHP-FPM сервера. Этот файл в репозитории настроен для Docker.

Настроить HTTPS в панели, один канонический домен и один переход на HTTPS. В PHP: `upload_max_filesize=20M`, `post_max_size=24M`, `memory_limit=256M`; веб-сервер должен принимать запросы до 24 МБ. Настройки Docker находятся в `deploy/php-production.ini`.

Не удалять завершающие слеши или добавлять их ко всем URL общим правилом: адреса автомобилей сохранены без завершающего слеша, городов — с ним. Публичные файлы `/static/` и `/media/` выдавать напрямую, CSS/JS/HTML с gzip или Brotli. Версионированные ресурсы можно кешировать долго; динамические HTML-страницы с формами и сессиями не кешировать общим публичным кешем.

## 6. Проверить перед переключением

- `/healthz/` отвечает 200 и `{"status":"ok"}`.
- Главная, города, 91 URL автомобилей и дополнительные страницы открываются.
- `/admin/`: вход прежней учётной записью, фото, цены, тексты, переводы, заявки.
- Форма создаёт заявку; неправильные данные и отсутствие согласия отклоняются.
- Постер появляется, анимация прокручивается вперёд и назад на компьютере и телефоне; без JS и при reduced-motion остаётся постер.
- Галерея, сортировка, фильтры, сохранение города и ссылки WhatsApp работают.
- Старые 27 ошибочных URL остаются 404 и не попадают в sitemap.
- Canonical и og:url относятся к собственным страницам. Неполные KK/EN-переводы не индексируются и не включаются в hreflang/sitemap.

После проверки окончательного домена поставить `IS_STAGING=false`, `ANALYTICS_ENABLED=true`, затем `php artisan optimize`. Проверить robots.txt, sitemap и отсутствие X-Robots-Tag noindex на опубликованных RU-страницах. В GTM должны быть **Метрика 92545653** и **GA4 G-00P3VJTEK9**: код сайта подключает только GTM.

## 7. Резервные копии и обновления

Ежедневно сохранять SQL, public/media и .env в закрытое хранилище вне public. Пример ручного SQL-бэкапа (пароль вводится интерактивно):

```bash
mysqldump -h DB_HOST -u DB_USER -p --single-transaction --no-tablespaces --default-character-set=utf8mb4 DB_NAME > /закрытый_каталог/legion.sql
```

Не хранить копии с заявками в публичном GitHub. Регулярно проверять восстановление в отдельную пустую базу. Cron/планировщик используется для бэкапов; очереди приложения сейчас синхронные и отдельный worker для отправки формы не требуется.

При обновлении: бэкап → новый код → Composer → `php artisan migrate --force` → `php artisan legion:publish-locales` → `php artisan optimize` → `php artisan filament:optimize` → проверка форм и страниц. Полный исходный импорт повторно не запускать. Откат выполняется возвратом предыдущего кода и проверенной резервной копии, без `migrate:fresh` на рабочей базе.

Официальные сведения: [развёртывание Laravel](https://laravel.com/framework/docs/13.x/deployment), [Artisan в панели PS.kz](https://docs.ps.kz/ru/hosting/website-hosting/control-panel/php-artisan-commands). Настройки конкретного аккаунта и доступность PHP 8.4 проверяются перед загрузкой.
