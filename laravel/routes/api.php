<?php

declare(strict_types=1);

use App\Http\Controllers\Matomo\Api\VersionController;
use Illuminate\Support\Facades\Route;

Route::match(['get', 'post'], '/index.php', VersionController::class)->name('matomo.api.version');
