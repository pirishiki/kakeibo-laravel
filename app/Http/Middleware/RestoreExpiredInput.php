<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ログインが切れていて保存できなかったフォームの入力を、ログインし直してその画面に戻ったときに、欄へ戻す（画面設計書 4 章の 12）。
 * 入力は、保存できなかったときに bootstrap/app.php がセッションに取っておく（ログインしてもセッションの中身は引きつがれる）
 */
class RestoreExpiredInput
{
    /** 取っておく時間（秒）。これより古い入力は戻さない */
    public const KEEP_SECONDS = 86400;

    public function handle(Request $request, Closure $next): Response
    {
        $saved = $request->session()->get('expired_input');
        if (is_array($saved) && $request->isMethod('GET') && $request->user() !== null && ! $request->hasHeader('X-Livewire')) {
            if (($saved['at'] ?? 0) < now()->timestamp - self::KEEP_SECONDS) {
                $request->session()->forget('expired_input');
            } elseif (self::place((string) ($saved['url'] ?? '')) === self::place($request->fullUrl())) {
                $request->session()->forget('expired_input');
                // この画面を出すときだけ、old() で欄に戻す
                $request->session()->now('_old_input', $saved['input'] ?? []);
                $request->session()->now('warning', 'ログインする前に入れた内容を、欄に戻しました。確かめてから、もう一度保存してください。');
            }
        }

        return $next($request);
    }

    /** 画面の場所（パスとクエリ）。http/https やポートのちがい（CloudFront の前と後など）では、別の画面にしない */
    private static function place(string $url): string
    {
        $parts = parse_url($url);

        return ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
    }
}
