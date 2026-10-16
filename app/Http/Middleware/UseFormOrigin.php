<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * フォームを送った画面（resources/js/app.js が送るときに入れる _from）を、ブラウザが Referer を送らないときの「前の画面」にする。
 * 入力のまちがい・時間切れなどで「前の画面」へ戻すとき、Referer がないと Laravel は「最後に開いた画面」（ほかのタブのこともある）へ戻すので、
 * 打った内容が、ほかの画面の同じ名前の欄に入ってしまわないように
 */
class UseFormOrigin
{
    public function handle(Request $request, Closure $next): Response
    {
        $from = $request->input('_from');
        if (! $request->isMethod('GET') && ! $request->headers->has('referer') && is_string($from) && self::isAppPath($from)) {
            $request->headers->set('referer', url($from));
        }

        return $next($request);
    }

    /** フォームを送った画面の URL（このアプリの中の画面だけ）。わからないときは null（Laravel の back() とちがい、「最後に開いた画面」は使わない） */
    public static function origin(Request $request): ?string
    {
        $referer = $request->headers->get('referer');
        if (! is_string($referer)) {
            return null;
        }
        // このアプリの中の場所だけの形（/accounts/create）も受けとる
        if (str_starts_with($referer, '/')) {
            return self::isAppPath($referer) ? url($referer) : null;
        }

        // 移す先の見出し（Location）が壊れないように、空白・改行などの見えない文字が入った URL は使わない
        return parse_url($referer, PHP_URL_HOST) === $request->getHost() && preg_match('/[\x00-\x20\x7f]/', $referer) !== 1 ? $referer : null;
    }

    /**
     * このアプリの中の場所か：/ で始まり、// や /\ で始まらない（ほかのサイトにならない）。
     * 改行・空白などの見えない文字と \ は入れない（移す先の見出しが壊れて、エラーの画面にならないように）
     */
    private static function isAppPath(string $path): bool
    {
        return preg_match('#^/(?![/\\\\])[^\x00-\x20\x7f\\\\]*$#', $path) === 1;
    }
}
