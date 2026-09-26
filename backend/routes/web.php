<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['name' => 'Store ERP API', 'api' => '/api/v1']));
