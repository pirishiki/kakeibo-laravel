<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // 本番では CloudFront が代わりに受けとって EC2 に渡す（プロキシ）。CloudFront がつける X-Forwarded-For から、
        // 使っている人の本当の IP アドレスを取る（ログインの回数の制限を、人ごとに数えるため。N-05）。
        // 信じる範囲は「すべて」。EC2 は外から入れない場所にあり、CloudFront 以外からは届かないため（全体構成設計書 2・3 章）
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
