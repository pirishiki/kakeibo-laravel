<?php

use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;

// ログイン・ログアウト。処理は Fortify のものを使う（回数の制限・セッションの作り直しなどもふくむ）。
// Fortify の URL はすべて止めてあり（FortifyServiceProvider）、使うものだけをここに書く
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
});
Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->middleware('auth')->name('logout');

// ここから下は、ログインしないと見られない。ログインしていなければ /login へ移る
Route::middleware('auth')->group(function () {
    Route::view('/', 'home')->name('home');
});
