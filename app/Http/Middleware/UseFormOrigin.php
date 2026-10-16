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
        // このアプリの中の場所（/ で始まり、// で始まらない）だけ使う
        if (! $request->isMethod('GET') && ! $request->headers->has('referer') && is_string($from)
            && str_starts_with($from, '/') && ! str_starts_with($from, '//')) {
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
        if (str_starts_with($referer, '/') && ! str_starts_with($referer, '//')) {
            return url($referer);
        }

        return parse_url($referer, PHP_URL_HOST) === $request->getHost() ? $referer : null;
    }
}
