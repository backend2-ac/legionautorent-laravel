<?php

$adminPath = trim((string) env('ADMIN_PATH', 'admin'), '/');

return [
    'display_timezone' => env('DISPLAY_TIMEZONE', 'Asia/Almaty'),
    'site_url' => rtrim(env('SITE_URL', 'https://legionautorent.kz'), '/'),
    'staging' => env('IS_STAGING', true), 'analytics' => env('ANALYTICS_ENABLED', false),
    'gtm' => env('GTM_ID'), 'whatsapp' => env('WHATSAPP_NUMBER'),
    'ffprobe' => env('FFPROBE_PATH', 'ffprobe'), 'ffmpeg' => env('FFMPEG_PATH', 'ffmpeg'),
    'admin_path' => $adminPath === 'control-legion' ? 'admin' : $adminPath,
];
