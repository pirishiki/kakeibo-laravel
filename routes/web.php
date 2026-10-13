<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\PartnerController;
use App\Http\Controllers\RecordController;
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

    // 分類の管理（画面設計書 5.8）。口座と同じ形に、ドラッグの並べかえ（reorder）を足したもの
    // reorder は resource より先に書く（あとに書くと、/categories/reorder の reorder が分類の id として読まれてしまうため）
    Route::patch('/categories/reorder', [CategoryController::class, 'reorder'])->name('categories.reorder');
    Route::resource('categories', CategoryController::class)->except(['show']);
    Route::patch('/categories/{category}/deactivate', [CategoryController::class, 'deactivate'])->name('categories.deactivate');

    // 相手の管理（画面設計書 5.9）。口座と同じ形に、ほかの相手にまとめる（merge）を足したもの
    Route::resource('partners', PartnerController::class)->except(['show']);
    Route::patch('/partners/{partner}/deactivate', [PartnerController::class, 'deactivate'])->name('partners.deactivate');
    Route::post('/partners/{partner}/merge', [PartnerController::class, 'merge'])->name('partners.merge');

    // 記録の一覧（画面設計書 5.3）。見る月は ?month=2026-09 のように URL のうしろにつける
    Route::get('/records', [RecordController::class, 'index'])->name('records.index');

    // 記録の登録（画面設計書 5.4）。入力欄は Livewire の部品なので、画面を出すだけでよい
    Route::view('/records/create', 'records.create')->name('records.create');
});
