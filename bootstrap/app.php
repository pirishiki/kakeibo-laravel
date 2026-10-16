<?php

use App\Http\Middleware\RestoreExpiredInput;
use App\Http\Middleware\UseFormOrigin;
use App\Support\ConcurrencyError;
use App\Support\DeletedElsewhere;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
        // ログインが切れて保存できなかった入力を、ログインし直したあとに欄へ戻す（画面設計書 4 章の 12）
        $middleware->web(append: RestoreExpiredInput::class);
        // ブラウザが Referer を送らないときも、フォームを送った画面へ戻せるようにする（app/Http/Middleware/UseFormOrigin.php）
        $middleware->web(prepend: UseFormOrigin::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // 画面を開いたまま時間がたって、送ったフォームの合言葉（CSRF トークン）が古くなったとき（419）。
        // 英語のエラーの画面を出して入力を消すかわりに、前の画面に戻して、入れた内容を欄に戻し、保存できなかったことを知らせる。
        // ログインも切れているときは、入れた内容をセッションに取っておき、ログインの画面で知らせる。
        // ログインし直すと、その画面に戻り、取っておいた内容を欄に戻す（app/Http/Middleware/RestoreExpiredInput.php）
        $exceptions->render(function (HttpException $e, Request $request) {
            if (! $e->getPrevious() instanceof TokenMismatchException) {
                return null;
            }
            if ($request->expectsJson() || $request->hasHeader('X-Livewire')) {
                return null;
            }
            $input = $request->except(['_token', '_method', '_from', 'password', 'password_confirmation']);
            // 戻り先は、フォームを送った画面（Referer。ないときは UseFormOrigin が _from から入れる）で、このアプリの中の画面だけ。
            // どちらもわからないときは、ほかのタブで最後に開いた画面の欄に入れてしまわないように、入れた内容を持たずにホームで知らせる
            if ($request->is('login')) {
                // ほかのタブでもうログインしていたときは、ログインの画面ではなくホームで知らせる（ログインの画面はホームへ移すため、知らせが消える）
                if (auth()->check()) {
                    return redirect()->route('home')->with('warning', 'もうログインしています。');
                }

                return redirect()->route('login')->withInput($input)
                    ->with('warning', '画面を開いてから時間がたっていたので、ログインできませんでした。もう一度ログインしてください。');
            }
            // ログアウトを押したときは、もうログインが切れているので、そのままログインの画面へ
            if (! auth()->check() && $request->is('logout')) {
                return redirect()->route('login');
            }
            $back = UseFormOrigin::origin($request);
            if ($back === null) {
                return redirect()->route(auth()->check() ? 'home' : 'login')
                    ->with('warning', '画面を開いてから時間がたっていたので、操作できませんでした。画面を開き直して、もう一度入れてください。');
            }
            if (! auth()->check()) {
                $request->session()->put('expired_input', ['url' => $back, 'input' => $input, 'at' => now()->timestamp]);
                redirect()->setIntendedUrl($back);

                return redirect()->route('login')
                    ->with('warning', 'ログインが切れていたので、操作できませんでした。ログインし直すと、入れた内容を欄に戻します。');
            }

            return redirect()->to($back)->withInput($input)
                ->with('warning', '画面を開いてから時間がたっていたので、操作できませんでした。入れた内容は残してあるので、もう一度押してください。');
        });

        // ほかの画面の保存と押さえあって、やり直しを使い切ったとき。エラーの画面のかわりに、入力を残して前の画面で知らせる
        // （app/Support/ConcurrencyError.php。Livewire の画面は AppServiceProvider で知らせる）
        // （ふだんは QueryException。トランザクションの中のトランザクションでは、やり直さずに DeadlockException が届く。どちらも PDOException の仲間）
        $exceptions->render(function (PDOException $e, Request $request) {
            if (! ConcurrencyError::is($e) || $request->hasHeader('X-Livewire')) {
                return null;
            }
            if ($request->expectsJson()) {
                return response()->json(['message' => ConcurrencyError::MESSAGE], 409);
            }

            // 戻り先がわからないときは、ほかのタブで最後に開いた画面の欄に入れてしまわないように、入れた内容を持たずにホームで知らせる
            $back = UseFormOrigin::origin($request);
            if ($back === null) {
                return redirect()->route('home')->with('warning', 'ほかの画面の保存と重なったので、保存できませんでした。画面を開き直して、もう一度入れてください。');
            }

            return redirect()->to($back)->withInput($request->except(['_token', '_method', '_from', 'password', 'password_confirmation']))
                ->with('warning', ConcurrencyError::MESSAGE);
        });

        // 開いた・保存した口座や分類などが、ほかの画面で削除されていたとき（404）。エラーの画面のかわりに、一覧へ戻して知らせる
        // （app/Support/DeletedElsewhere.php）。Livewire の部品は、部品の中で知らせる
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            $missing = $e->getPrevious();
            if (! $missing instanceof ModelNotFoundException || $request->expectsJson() || $request->hasHeader('X-Livewire')) {
                return null;
            }

            return DeletedElsewhere::response($missing, $request);
        });
    })->create();
