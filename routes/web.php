<?php

use App\Http\Controllers\BookingController;
use App\Http\Controllers\SeoController;
use App\Http\Controllers\SiteController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/control-legion/{path?}', function (Request $request, ?string $path = null) {
    $target = rtrim(route('filament.admin.pages.dashboard', [], false), '/');
    if ($path !== null) {
        $target .= '/'.ltrim($path, '/');
    }
    if ($query = $request->getQueryString()) {
        $target .= '?'.$query;
    }

    return redirect($target, 301);
})->where('path', '.*');

Route::get('/healthz/', function () {
    try {
        DB::select('SELECT 1');

        return response()->json(['status' => 'ok']);
    } catch (Throwable) {
        return response()->json(['status' => 'unavailable'], 503);
    }
});
Route::get('/robots.txt', [SeoController::class, 'robots']);
Route::get('/sitemap.xml', [SeoController::class, 'sitemap']);
Route::get('/sitemap-{section}.xml', [SeoController::class, 'sitemap']);
Route::get('/img/{path}', [SeoController::class, 'legacyImage'])->where('path', '.*');
Route::get('/video/video-2.mp4', function () {
    $p = public_path('media/legacy/video-2.mp4');
    abort_unless(is_file($p), 404);

    return response()->file($p, ['Content-Type' => 'video/mp4']);
});
foreach (['', 'kk/', 'en/'] as $prefix) {
    foreach (['booking/', 'callback/'] as $path) {
        Route::post('/'.$prefix.$path, [BookingController::class, 'store']);
    }
}
Route::get('/', SiteController::class);
Route::fallback(SiteController::class);
