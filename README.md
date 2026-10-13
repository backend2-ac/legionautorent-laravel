# LEGIONAUTORENT — Laravel / MySQL

Отдельный перенос утверждённого Django-сайта. Исходный проект `D:\legionautorent.kz` сохранён. Laravel находится в `D:\legionautorent-laravel`.

Сохранены 91 автомобиль, 302 записи фотографий, 364 скидки, 4 города, 95 исходных работающих URL, SEO-поля, тексты, языковые версии, заявки и существующие учётные записи. Публичные шаблоны работают через Blade; Django и PostgreSQL для работы этой версии не нужны. Админка построена на Filament 5.

## Документы

- [Деплой на PS.kz](DEPLOY_PS_KZ.md)
- [Работа с админкой](ADMIN_GUIDE.md)
- [Результаты переноса и проверки](MIGRATION_REPORT.md)
- [Производительность](PERFORMANCE_REPORT.md)
- [Проверка админки и изменений](RELEASE_CHECKLIST.md)
- [Сравнение с действующим сайтом](SEO_LIVE_COMPARISON.md)

## Требования

PHP 8.4, Composer 2, MySQL 8.4, Node.js 22 для пересборки фронтенда. PHP: `pdo_mysql`, `intl`, `mbstring`, `gd` с WebP, `exif`, `zip`, `fileinfo`, `dom`, `xml`, `xmlreader`, `openssl`. AVIF используется, если GD поддерживает его. Готовые публичные файлы сборки уже включены; Node.js не нужен для обслуживания посетителей.

MySQL использует `utf8mb4_bin`: в исходных данных есть ключи, различающиеся регистром. Не заменять её на нечувствительную к регистру сортировку.

## Состав данных

`resources/data/catalogue-snapshot.json` — публичный каталог для тестов и демонстрации, **без пользователей, паролей и заявок**. Полная частная копия находится только локально в `storage/app/migration/django-snapshot.json`. Фото находятся в `public/media/` и не включены в Git. Для настоящего переноса нужны отдельные архив медиа и частный снимок; не публикуйте их.

`app/` — PHP-модели, контроллеры, CMS и правила. `resources/views/` — Blade. `frontend/` — исходные CSS/JS. `public/static/` — шрифты, бренд, анимация и сборка. `database/migrations/` — схема MySQL. `deploy/`, Dockerfile и compose.yaml — запуск с PHP-FPM/Nginx. `tests/` — изолированные проверки.

## Установка из чистой копии

Сначала создать **новую пустую** MySQL-базу и пользователя. Затем:

```bash
cp .env.example .env
# Заполнить DB_* и APP_URL в .env
composer install --prefer-dist
php artisan key:generate
php artisan migrate --force
php artisan legion:import-django storage/app/migration/django-snapshot.json --dry-run
php artisan legion:import-django storage/app/migration/django-snapshot.json
php artisan legion:publish-locales
# Распаковать архив медиа в public/media/
npm ci --ignore-scripts
npm run build
php artisan serve --host=127.0.0.1 --port=8003
```

Для демонстрации без частного снимка вместо импорта можно использовать `php artisan db:seed`. После `db:seed` выполнить `php artisan legion:publish-locales`. После этого импорт полного снимка в ту же базу будет отклонён: импорт намеренно не перезаписывает существующие записи. Создать администратора демонстрации: `php artisan legion:admin ваш_логин` — пароль вводится скрыто в терминале.

Админка: `/admin/` (`ADMIN_PATH=admin`). Старые ссылки `/control-legion/...` перенаправляются на новый адрес; прежнее значение ADMIN_PATH автоматически заменяется на admin. После полного импорта действуют прежние логин и пароль. При первом входе Django-хеш обновляется до bcrypt, сам пароль сохраняется.

## Этот компьютер

PHP, Composer, MySQL и их кеши установлены внутри `.local/` на D. Локальный MySQL: `127.0.0.1:3307`. Предпросмотр: `http://127.0.0.1:8003/`. После перезагрузки MySQL можно запустить командой:

```powershell
& '.local/mysql/mysql-8.4.11-winx64/bin/mysqld.exe' '--defaults-file=D:/legionautorent-laravel/.local/my.ini' --console
```

В другом терминале:

```powershell
& '.local/php/php.exe' artisan serve --host=127.0.0.1 --port=8003
```

Для npm/Composer задавать кеш и TEMP/TMP на D, если продолжаете разработку здесь. `.local/`, `.env`, логи и личные данные исключены из Git.

## Docker

```bash
docker compose up -d --build
docker compose exec app php artisan migrate --force
docker compose exec app php artisan legion:import-django storage/app/migration/django-snapshot.json
docker compose exec app php artisan legion:publish-locales
docker compose exec app php artisan optimize
docker compose exec app php artisan filament:optimize
```

Нужны `.env`, полный снимок и `public/media/`. Адрес: `http://127.0.0.1:8004/`. Для HTTP-предпросмотра `SESSION_SECURE_COOKIE=false`; для публичного HTTPS — `true`. `APP_URL` должен совпадать с адресом выбранного способа запуска. Нативная и Docker-базы независимы. Для Docker задаётся DOCKER_APP_URL. Сессии, кеш и логи контейнера находятся в отдельном томе runtime; на этом компьютере он физически хранится на D. Снимок монтируется отдельно только для чтения, медиа остаются в public/media на D.

На этом компьютере Docker Desktop хранит образы/контейнеры/тома в `D:\DockerData\wsl`. В локальном `.env` выбран исправный `MYSQL_IMAGE=mysql:8.4.10`, поскольку ранее загруженный 8.4.11 повреждён после нехватки места. Обычная новая сборка использует 8.4.11. Другие контейнеры и общие образы сохранены. Не запускать `docker compose down -v`: это удалит базу этой сборки.

## Проверки

Тестам нужна отдельная база **`legion_test`** и права на неё. Запуск: `php artisan test --compact`. Встроенная проверка блокирует тестовые миграции в любой другой базе. Фото в PHPUnit создаются на поддельном диске: тесты не используют и не изменяют рабочие медиа. Для MySQL в CI указать порт через `DB_PORT`; пример — `.github/workflows/ci.yml`.

Не запускать `migrate:fresh` на рабочей базе. Перед обновлением делать резервную копию БД, медиа и `.env`. Сайт пока работает в режиме staging: индексация и аналитика включаются отдельно после проверки домена.
