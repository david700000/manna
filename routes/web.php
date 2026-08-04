<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Keep-alive ping — works at both /ping and /api/ping
Route::get('/ping', fn () => response()->json(['ok' => true]));
