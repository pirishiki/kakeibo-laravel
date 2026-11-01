<?php

namespace App\Livewire\Concerns;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Throwable;

/**
 * 新しく登録する画面で、保存が 2 回届いても 1 件だけ作る（画面設計書 5.4・5.10、5.13 の「2 回押し」と同じ考え方）。
 *
 * 同じ内容の記録は、ふつうに何件もあるので、内容だけでは 2 回目かどうか見分けられない。
 * そこで、画面を開くたびに「送信用の合言葉」を 1 つ作っておき、保存するときに使い切る。
 * 合言葉には「保存中」か「保存済み＋保存した中身の印」を覚えておき、2 回目が届いたときは次のようにする。
 * - 保存済みで、中身が同じ：2 回押しなので、何も作らず、1 回目と同じ流れで戻る
 * - 保存済みで、中身がちがう：ブラウザの「戻る」で保存済みの画面に戻って別の内容を入れたときなど。
 *   作れないのに「保存しました」と出すと、入れた内容が黙って消えるので、保存していないと知らせる
 * - 保存中：1 回目が終わるまで待ってもらう（1 回目が失敗したときに、2 回目まで成功と言ってしまわないため）
 * 保存の途中で失敗したときは合言葉を戻して、もう一度押せば保存できるようにする。
 * 合言葉は 1 日で期限が切れる（期限切れの行は、毎日の片づけで消す。routes/console.php）
 */
trait PreventsDoubleSubmit
{
    /** この画面の送信用の合言葉。画面の側から書きかえられないようにする（他の画面の合言葉を使い切れないように） */
    #[Locked]
    public string $submitToken = '';

    /** 新しく登録する画面を開いたときに呼ぶ */
    protected function startSubmitToken(): void
    {
        $this->submitToken = (string) Str::uuid();
    }

    /**
     * 保存を 1 回だけ行う。新しく登録するとき（$isNew）は、合言葉を使い切ってから保存する。
     * 変更のときは、そのまま保存する（変更は、同じ内容を 2 回保存しても件数が増えないため）
     *
     * @param  array<mixed>  $content  保存する中身（2 回目が同じ中身かを見分ける印にする）
     * @param  Closure(): mixed  $write  保存する処理。返した値を、そのまま返す
     * @param  string  $errorKey  保存できなかったことを知らせる欄の名前
     * @param  mixed  $whenDuplicate  同じ中身の 2 回目だったときに返す値
     */
    protected function saveOnce(bool $isNew, array $content, Closure $write, string $errorKey, mixed $whenDuplicate = null): mixed
    {
        if (! $isNew) {
            return $write();
        }

        $key = 'submitted:'.$this->submitToken;
        $hash = md5(json_encode($content, JSON_THROW_ON_ERROR));
        // Cache::add は「なければ入れる」を 1 回で行うので、2 つの通信が同時に来ても、通るのは 1 つだけ
        if (! Cache::add($key, ['state' => 'saving', 'hash' => $hash], now()->addDay())) {
            $state = Cache::get($key);
            if (is_array($state) && ($state['state'] ?? null) === 'saved' && ($state['hash'] ?? null) === $hash) {
                return $whenDuplicate;
            }

            throw ValidationException::withMessages([$errorKey => is_array($state) && ($state['state'] ?? null) === 'saving'
                ? 'いま保存しています。終わるまで少し待ってから、もう一度押してください'
                : 'この画面からは、もう保存しています。続けて入れるときは、画面を開き直してください（「保存して続けて入力」を使うと、すぐに次を入れられます）']);
        }

        try {
            $result = $write();
        } catch (Throwable $e) {
            Cache::forget($key);

            throw $e;
        }
        Cache::put($key, ['state' => 'saved', 'hash' => $hash], now()->addDay());

        return $result;
    }
}
