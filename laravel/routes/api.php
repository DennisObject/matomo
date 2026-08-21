<?php

declare(strict_types=1);

use App\Http\Controllers\Matomo\FrontController;
use App\Http\Controllers\Matomo\TrackerController;
use App\Http\Middleware\StartMatomoUiSession;
use Illuminate\Support\Facades\Route;

Route::match(['get', 'post'], '/index.php', FrontController::class)
    ->middleware([StartMatomoUiSession::class])
    ->name('matomo.api.reporting');
Route::match(['get', 'post'], '/matomo.php', TrackerController::class)->name('matomo.tracker');
Route::match(['get', 'post'], '/piwik.php', TrackerController::class)->name('piwik.tracker');
