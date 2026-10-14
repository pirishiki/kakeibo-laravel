<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 本番では、Laravel が作る URL（ログインのあとの移り先など）をいつも https にする（全体構成設計書 2 章）。
        // CloudFront から EC2 へは http で届くので、何もしないと Laravel は自分が http で呼ばれたと思ってしまう。
        // http で開かれたときは、CloudFront が https に移す
        if ($this->app->isProduction()) {
            URL::forceHttps();
        }
    }
}
