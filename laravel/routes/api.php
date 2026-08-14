<?php

declare(strict_types=1);

use App\Http\Controllers\Matomo\Api\ReportingApiController;
use Illuminate\Support\Facades\Route;

Route::match(['get', 'post'], '/index.php', ReportingApiController::class)->name('matomo.api.reporting');
