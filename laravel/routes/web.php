<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'name' => 'Matomo Laravel runtime',
    'status' => 'foundation',
], 503))->name('foundation');
