<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * 「送信用の合言葉」の置き場。新しく追加する画面で、保存が 2 回届いても 1 件だけ作るために使う
 * （記録・定期的な記録は Livewire の PreventsDoubleSubmit、口座・分類・相手は PreventsDoubleAdd が、これを使う）。
 *
 * 合言葉は、追加の画面を開くたびに 1 つ作る。キャッシュに「保存中」か「保存済み＋保存した中身の印」を覚えておき、
 * 同じ合言葉で 2 回目が届いたときの扱いを決める：
 * - SAME：保存済みで、中身が同じ（2 回押し）。何も作らず、1 回目と同じ流れで戻る
 * - SAVED：保存済みで、中身がちがう（ブラウザの「戻る」で保存済みの画面に戻り、別の内容を入れたときなど）。
 *   「保存しました」と出すと入れた内容が黙って消えるので、保存していないと知らせる
 * - SAVING：保存中。1 回目が終わるまで待ってもらう（1 回目が失敗したときに、2 回目まで成功と言ってしまわないため）
 * 保存の途中で失敗したときは、合言葉を戻して、もう一度押せば保存できるようにする。期限は 1 日（期限切れの行は cache:prune-expired が消す）
 */
final class SubmitOnce
{
    public const NEW = 'new';

    public const SAME = 'same';

    public const SAVED = 'saved';

    public const SAVING = 'saving';

    public const SAVING_MESSAGE = 'いま保存しています。終わるまで少し待ってから、もう一度押してください';

    /** 保存済みの画面で別の内容を保存しようとしたときの文（追加の画面。記録の画面は、続けて入力のボタンの案内もつける） */
    public const SAVED_MESSAGE = 'この画面からは、もう保存しています。続けて入れるときは、画面を開き直してください';

    /** 保存する中身の印 */
    public static function hash(array $content): string
    {
        // json_encode は、正しくない文字コードの値が送られると失敗してエラーの画面になるので、serialize を使う
        return md5(serialize($content));
    }

    /**
     * 合言葉を使い切る。最初の 1 回だけ NEW を返し、2 回目以降は SAME・SAVED・SAVING のどれかを返す。
     * Cache::add は「なければ入れる」を 1 回で行うので、2 つの通信が同時に来ても、NEW になるのは 1 つだけ
     */
    public static function claim(string $token, string $hash, bool $retry = true): string
    {
        if (Cache::add(self::key($token), ['state' => 'saving', 'hash' => $hash], now()->addDay())) {
            return self::NEW;
        }

        $state = self::state($token);
        // 入れようとしてから読むまでの間に、1 回目が失敗して合言葉を戻したとき（行がない）は、もう一度使い切りから始める
        // （何も保存していないのに「保存中」と出さないため）
        // （それでも読めないときは、キャッシュが使えない状態。保存を止めないよう、使い切れたものとして進める）
        if ($state === null) {
            return $retry ? self::claim($token, $hash, false) : self::NEW;
        }
        if (($state['state'] ?? null) === 'saved') {
            return ($state['hash'] ?? null) === $hash ? self::SAME : self::SAVED;
        }

        return self::SAVING;
    }

    /**
     * 保存できた。「保存済み＋中身の印」に書きかえる
     *
     * @param  array<string, mixed>  $extra  2 回目のときに使いたい情報（戻り先など）
     */
    public static function saved(string $token, string $hash, array $extra = []): void
    {
        Cache::put(self::key($token), ['state' => 'saved', 'hash' => $hash, ...$extra], now()->addDay());
    }

    /** 保存の途中で失敗した。合言葉を戻す */
    public static function release(string $token): void
    {
        Cache::forget(self::key($token));
    }

    /** @return array<string, mixed>|null */
    public static function state(string $token): ?array
    {
        $state = Cache::get(self::key($token));

        return is_array($state) ? $state : null;
    }

    private static function key(string $token): string
    {
        return 'submitted:'.$token;
    }
}
