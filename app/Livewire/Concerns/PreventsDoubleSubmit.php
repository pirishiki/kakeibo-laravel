<?php

namespace App\Livewire\Concerns;

use App\Support\SubmitOnce;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Throwable;

/**
 * 新しく登録する画面で、保存が 2 回届いても 1 件だけ作る（画面設計書 5.4・5.7・5.10、5.13 の「2 回押し」と同じ考え方）。
 *
 * 同じ内容の記録は、ふつうに何件もあるので、内容だけでは 2 回目かどうか見分けられない。
 * そこで、画面を開くたびに「送信用の合言葉」を 1 つ作っておき、保存するときに使い切る。
 * 合言葉には「保存中」か「保存済み＋保存した中身の印」を覚えておき、2 回目が届いたときは次のようにする。
 * - 保存済みで、中身が同じ：2 回押しなので、何も作らず、1 回目と同じ流れで戻る。
 *   ただし、1 回目で作ったものが、ほかの画面で削除されていたら、作り直さず、保存していないと知らせる（2026-11-07 に決定）
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

    /**
     * 1 回目の保存で作ったもの（記録・定期的な記録）。保存の処理の中で rememberCreated() で入れる。
     * private なので画面には送らず、1 回の通信の中だけで使う（保存済みの印といっしょにキャッシュに覚える）
     *
     * @var array{model: class-string<Model>, id: mixed}|null
     */
    private ?array $createdOnSubmit = null;

    /** 新しく登録する画面を開いたときに呼ぶ */
    protected function startSubmitToken(): void
    {
        $this->submitToken = (string) Str::uuid();
    }

    /**
     * 保存の処理（saveOnce に渡す $write の中）で、作ったものを覚えておく。
     * 2 回目が届いたとき、それがまだあるかを確かめる（1 回目のあと、ほかの画面で削除されていたら、何もないのに「保存しました」と出さないため。
     * 画面設計書 5.4・5.7・5.10。2026-11-07 に決定）。押さえあいでやり直したときは、最後に作ったもので上書きされる
     */
    protected function rememberCreated(Model $model): void
    {
        $this->createdOnSubmit = SubmitOnce::created($model);
    }

    /**
     * 保存を 1 回だけ行う。新しく登録するとき（$isNew）は、合言葉を使い切ってから保存する。
     * 変更のときは、そのまま保存する（変更は、同じ内容を 2 回保存しても件数が増えないため）
     *
     * @param  array<mixed>  $content  保存する中身（2 回目が同じ中身かを見分ける印にする）
     * @param  Closure(): mixed  $write  保存する処理。返した値を、そのまま返す
     * @param  string  $errorKey  保存できなかったことを知らせる欄の名前
     * @param  mixed  $whenDuplicate  同じ中身の 2 回目だったときに返す値
     * @param  string  $savedHint  保存済みの画面で別の内容を保存しようとしたときの知らせに、足す案内（その画面にあるボタンの案内など）
     */
    protected function saveOnce(bool $isNew, array $content, Closure $write, string $errorKey, mixed $whenDuplicate = null, string $savedHint = ''): mixed
    {
        if (! $isNew) {
            return $write();
        }

        // 合言葉の扱い（保存中・保存済み・2 回目の見分け）は、口座・分類・相手の追加と共通（SubmitOnce）
        $hash = SubmitOnce::hash($content);
        $claim = SubmitOnce::claim($this->submitToken, $hash);
        if ($claim === SubmitOnce::SAME) {
            // 1 回目で作ったものが、そのあとほかの画面で削除されていた：同じ合言葉の 2 回目なので作り直さず、「保存しました」とも言わずに知らせる。
            // 打った内容は欄に残し、合言葉を新しくする（もう一度押せば、新しい保存として作れる。押すたびに同じ知らせで止まりつづけないように）。
            // 合言葉の使い切り（claim）はそのままなので、2 回目が同時に 2 つ届いても、どちらも作らない
            $state = SubmitOnce::state($this->submitToken);
            if (SubmitOnce::createdIsGone($state)) {
                $this->startSubmitToken();

                throw ValidationException::withMessages([$errorKey => SubmitOnce::goneMessage($state, '保存')]);
            }

            return $whenDuplicate;
        }
        if ($claim !== SubmitOnce::NEW) {
            throw ValidationException::withMessages([$errorKey => $claim === SubmitOnce::SAVING
                ? SubmitOnce::SAVING_MESSAGE
                : SubmitOnce::SAVED_MESSAGE.$savedHint]);
        }

        $this->createdOnSubmit = null;
        try {
            $result = $write();
        } catch (Throwable $e) {
            SubmitOnce::release($this->submitToken);

            throw $e;
        }
        // 作ったものも、保存済みの印といっしょに覚える（2 回目のときに、まだあるかを確かめるため）
        SubmitOnce::saved($this->submitToken, $hash, $this->createdOnSubmit ?? []);

        return $result;
    }
}
