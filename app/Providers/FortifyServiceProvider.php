<?php

namespace App\Providers;

use App\Http\Requests\LoginRequest;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Requests\LoginRequest as FortifyLoginRequest;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // ログインの入力チェックを、メールアドレスの形も見るものに差しかえる
        $this->app->bind(FortifyLoginRequest::class, LoginRequest::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // ログイン画面（画面設計書 5.1）
        // 回数の制限は config/fortify.php の limiters のコメントを見る
        Fortify::loginView(fn () => view('auth.login'));
    }
}
