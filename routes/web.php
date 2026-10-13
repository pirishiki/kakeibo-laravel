<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\CategoryController;
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

    // 口座の管理（画面設計書 5.6）。1 つの口座だけを見る画面（show）はない。行を押すと変更の画面へ行く
    Route::resource('accounts', AccountController::class)->except(['show']);
    Route::patch('/accounts/{account}/deactivate', [AccountController::class, 'deactivate'])->name('accounts.deactivate');

    // 分類の管理（画面設計書 5.8）。口座と同じ形に、↑ ↓ の並べかえ（move）を足したもの
    Route::resource('categories', CategoryController::class)->except(['show']);
    Route::patch('/categories/{category}/deactivate', [CategoryController::class, 'deactivate'])->name('categories.deactivate');
    Route::patch('/categories/{category}/move', [CategoryController::class, 'move'])->name('categories.move');
});
