<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpKernel\Exception\HttpException;

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

        // 画面を開いたまま時間がたって、送ったフォームの合言葉（CSRF トークン）が古くなったとき（419）。
        // 英語のエラーの画面を出して入力を消すかわりに、前の画面に戻して、入れた内容を欄に戻し、保存できなかったことを知らせる。
        // ログインも切れているときは、ログインの画面で知らせる（入れた内容は戻せない）
        $exceptions->render(function (HttpException $e, Request $request) {
            if (! $e->getPrevious() instanceof TokenMismatchException) {
                return null;
            }
            if ($request->expectsJson() || $request->hasHeader('X-Livewire')) {
                return null;
            }
            $input = $request->except(['_token', '_method', 'password', 'password_confirmation']);
            if ($request->is('login')) {
                return redirect()->route('login')->withInput($input)
                    ->with('warning', '画面を開いてから時間がたっていたので、ログインできませんでした。もう一度ログインしてください。');
            }
            if (! auth()->check()) {
                return redirect()->route('login')->with('warning', 'ログインが切れていたので、操作できませんでした。ログインしてから、もう一度入れてください。');
            }

            return redirect()->back()->withInput($input)
                ->with('warning', '画面を開いてから時間がたっていたので、操作できませんでした。入れた内容は残してあるので、もう一度押してください。');
        });
    })->create();
