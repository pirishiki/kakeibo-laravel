<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\GoalController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PartnerController;
use App\Http\Controllers\RecordController;
use App\Http\Controllers\RecurringController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SummaryController;
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
    // ホーム（画面設計書 5.2）
    Route::get('/', [HomeController::class, 'index'])->name('home');

    // 口座の管理（画面設計書 5.6）。1 つの口座だけを見る画面（show）はない。行を押すと変更の画面へ行く
    Route::resource('accounts', AccountController::class)->except(['show']);
    Route::patch('/accounts/{account}/deactivate', [AccountController::class, 'deactivate'])->name('accounts.deactivate');
    // 残高を合わせる（画面設計書 5.7）。入力欄と保存は Livewire の部品（app/Livewire/AdjustBalance.php）
    Route::get('/accounts/{account}/adjust', [AccountController::class, 'adjust'])->name('accounts.adjust');

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
    // 記録の編集・削除（画面設計書 5.4）。{record} の番号から、その記録を Laravel が探して渡す（ない番号なら 404）
    Route::get('/records/{record}/edit', [RecordController::class, 'edit'])->name('records.edit');

    // 記録の登録（画面設計書 5.4）。入力欄は Livewire の部品なので、画面を出すだけでよい
    Route::view('/records/create', 'records.create')->name('records.create');

    // 月のまとめ（画面設計書 5.5）。見る月は記録の一覧と同じく ?month=2026-09
    Route::get('/summary', [SummaryController::class, 'index'])->name('summary.index');

    // 定期的な記録（画面設計書 5.10）。登録・変更の入力欄は Livewire の部品（app/Livewire/RecurringForm.php）
    // 月ごとの金額（一覧の上半分）は、次のかたまりで足す
    Route::get('/recurrings', [RecurringController::class, 'index'])->name('recurrings.index');
    Route::view('/recurrings/create', 'recurrings.create')->name('recurrings.create');
    Route::get('/recurrings/{recurring}/edit', [RecurringController::class, 'edit'])->name('recurrings.edit');

    // 予算を決める（画面設計書 5.11）。特別な月は ?month=2026-12。全部の欄を 1 回でまとめて保存する
    Route::get('/budgets', [BudgetController::class, 'index'])->name('budgets.index');
    Route::put('/budgets', [BudgetController::class, 'update'])->name('budgets.update');

    // 貯金の目標（画面設計書 5.13）。1 つの目標だけを見る画面（show）はない。行を押すと変更の画面へ行く
    Route::resource('goals', GoalController::class)->except(['show']);

    // 設定（画面設計書 5.12）。たまにしか使わない画面へのリンクを並べる
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
});
