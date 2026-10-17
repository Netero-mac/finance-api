<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->file(public_path('app.html')));
