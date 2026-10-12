<?php

use Illuminate\Support\Facades\Route;

// ログイン・ログアウトの URL は Fortify が作る（/login・/logout）

// ここから下は、ログインしないと見られない。ログインしていなければ /login へ移る
Route::middleware('auth')->group(function () {
    Route::view('/', 'home')->name('home');
});
