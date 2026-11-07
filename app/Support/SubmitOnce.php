<?php

namespace App\Support;

use App\Models\Account;
use App\Models\Category;
use App\Models\Goal;
use App\Models\Partner;
use App\Models\Record;
use App\Models\Recurring;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * 「送信用の合言葉」の置き場。新しく追加する画面で、保存が 2 回届いても 1 件だけ作るために使う
 * （記録・定期的な記録・残高を合わせるは Livewire の PreventsDoubleSubmit、口座・分類・相手・目標は PreventsDoubleAdd が、これを使う）。
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
        // 保存はもう済んでいる。キャッシュに書けなくても、保存できたことを失敗にしない（合言葉が「保存中」のまま残ると、
        // 保存済みなのに「いま保存しています」と出つづけるので、書けなかったら合言葉の行を消す。消せないときは期限（1 日）で消える）
        try {
            Cache::put(self::key($token), ['state' => 'saved', 'hash' => $hash, ...$extra], now()->addDay());
        } catch (Throwable $e) {
            report($e);
            try {
                self::release($token);
            } catch (Throwable) {
                // 期限で消えるのを待つ
            }
        }
    }

    /** 保存の途中で失敗した。合言葉を戻す */
    public static function release(string $token): void
    {
        Cache::forget(self::key($token));
    }

    /**
     * 1 回目で作ったものを、保存済みの印といっしょに覚える形（saved の $extra に入れる）
     *
     * @return array{model: class-string<Model>, id: mixed}
     */
    public static function created(Model $model): array
    {
        return ['model' => $model::class, 'id' => $model->getKey()];
    }

    /**
     * 同じ合言葉・同じ中身の 2 回目で、1 回目で作ったものが、もうないか（ほかの画面で削除された）。
     * そのときは作り直さず（同じ合言葉の 2 回目のため）、「保存しました」とも言わずに知らせる（何もないのに保存したと出さないため。
     * 画面設計書 5.4・5.6・5.7・5.10・5.13。2026-11-07 に決定）。覚えていないとき・形がおかしいときは、ないとは言わない
     *
     * @param  array<string, mixed>|null  $state
     */
    public static function createdIsGone(?array $state): bool
    {
        $model = $state['model'] ?? null;
        $id = $state['id'] ?? null;
        if (! is_string($model) || ! is_subclass_of($model, Model::class) || (! is_int($id) && ! is_string($id))) {
            return false;
        }

        return ! $model::query()->whereKey($id)->exists();
    }

    /**
     * 1 回目で作ったものが削除されていたときの文
     *
     * @param  array<string, mixed>|null  $state
     * @param  string  $again  もう一度押したときにできること（「追加」「保存」など）
     */
    public static function goneMessage(?array $state, string $again): string
    {
        $label = match ($state['model'] ?? null) {
            Account::class => '口座',
            Category::class => '分類',
            Partner::class => '相手',
            Goal::class => '目標',
            Record::class => '記録',
            Recurring::class => '定期的な記録',
            default => '内容',
        };

        // 相手は、削除のほかに、ほかの相手にまとめられても無くなる（画面設計書 5.9）ので、どちらかわかるように書く
        $gone = $label === '相手' ? 'ほかの画面で削除されたか、ほかの相手にまとめられていた' : 'ほかの画面で削除されていた';

        return 'この画面から保存した'.$label.'は、'.$gone.'ので、今回は保存していません。入れた内容を新しく'.$again.'するときは、もう一度「保存」を押してください';
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
