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
        // 信じる範囲は「すべて」。EC2 は外から入れない場所にあり、CloudFront 以外からは届かないため（全体構成設計書 2・3 章）。
        // 信じる見出しは X-Forwarded-For（IP アドレス）だけにする。CloudFront はブラウザが送った見出しをそのまま渡すので、
        // ほかの見出し（X-Forwarded-Host など）まで信じると、ブラウザが書いた値で URL が作られてしまうため
        $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_FOR);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
