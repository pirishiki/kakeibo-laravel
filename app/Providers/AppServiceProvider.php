<?php

namespace App\Providers;

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

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

        // Livewire の画面（記録の編集・定期的な記録の編集・残高を合わせる）は、打つたび・押すたびに、開いたページの URL で
        // 「URL の番号から行を探す」（SubstituteBindings）をもう一度通る。ほかの画面でその行が削除されていると、部品が
        // 「もう削除されていました」と知らせる前に 404 の小窓が出てしまうので、これだけ外す（ログインの確かめは残す）。
        // 部品は、どれも番号から自分で行を探し、ないときの知らせを持っている
        Livewire::setPersistentMiddleware(array_values(array_diff(Livewire::getPersistentMiddleware(), [SubstituteBindings::class])));
    }
}
