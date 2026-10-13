<?php

namespace App\Support;

/**
 * 入力の画面で共通に使う、打った値・URL のそろえ方（記録の登録・編集、残高を合わせる）
 */
class FormInput
{
    /** 金額の欄に入れられるいちばん大きい数（要件定義書 10.1。マイナスを入れる欄は、マイナスも同じ大きさまで） */
    public const MAX_AMOUNT = 99_999_999;

    /** 記録の日付に入れられる、いちばん前の日 */
    public const FIRST_DATE = '2000-01-01';

    /**
     * 金額の文字を、数だけの形にそろえる：全角の数字・マイナスを半角に、カンマと空白を取り、頭の 0 と「+」を取る（0100 → 100、-0100 → -100、+500 → 500）
     * 日本語入力のまま「-」を打つと出る「ー」も、マイナスとして読む。数の形でないときは、そのまま返す（入力チェックで「数字だけで入れてください」にする）
     */
    public static function normalizeAmount(string $amount): string
    {
        $text = str_replace(['－', '−', 'ー', '‐', '＋', ',', '，', ' '], ['-', '-', '-', '-', '+', '', '', ''], mb_convert_kana(trim($amount), 'ns'));

        if (preg_match('/^([-+]?)(\d+)$/', $text, $m) !== 1) {
            return $text;
        }
        $digits = ltrim($m[2], '0') ?: '0';

        return $digits === '0' ? '0' : ($m[1] === '-' ? '-' : '').$digits;
    }

    /** 「2026-10-13」の形の、ほんとうにある日付か */
    public static function isDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    /**
     * 戻る先：このアプリの中の画面で、$exclude の画面でないときだけ。それ以外はホーム。
     * URL の形（http・https）と場所（このアプリ）を確かめ、場所の部分だけを取り出して、このアプリの URL として組み立て直す
     * （「javascript:」で始まる URL などで、保存したときに危ない動きをさせないため）
     *
     * @param  list<string>  $exclude  戻る先にしない画面の、場所（パス）の形（正規表現）。保存したあとに同じ画面へ戻ってしまわないように
     */
    public static function backUrl(string $previous, array $exclude = []): string
    {
        $parts = parse_url($previous);
        $path = $parts['path'] ?? '/';
        $isApp = $parts !== false
            && in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            && ($parts['host'] ?? '') === request()->getHost()
            && str_starts_with($path, '/')
            // 「//よそのサイト」は、ブラウザがよそのサイトへの URL として読むので通さない
            && ! str_starts_with($path, '//');

        foreach ($exclude as $pattern) {
            $isApp = $isApp && preg_match($pattern, $path) !== 1;
        }

        return $isApp ? url($path).(isset($parts['query']) ? '?'.$parts['query'] : '') : route('home');
    }
}
