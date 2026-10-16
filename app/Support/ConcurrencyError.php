<?php

namespace App\Support;

use Illuminate\Database\DetectsConcurrencyErrors;
use Throwable;

/**
 * ほかの画面の保存と押さえあって（デッドロック・押さえの待ちの時間切れ）、やり直しを使い切ったときの知らせ。
 * 押さえる順番はそろえているが、新しい行を入れるときの「すきま」の押さえなど、順番では消せない待ち合いが残るため、
 * エラーの画面のかわりに、入力を残して「もう一度押してください」と知らせる（bootstrap/app.php・AppServiceProvider）
 */
class ConcurrencyError
{
    use DetectsConcurrencyErrors;

    public const MESSAGE = 'ほかの画面の保存と重なったので、保存できませんでした。入れた内容は残してあるので、もう一度押してください。';

    public static function is(Throwable $e): bool
    {
        return (new self)->causedByConcurrencyError($e);
    }
}
