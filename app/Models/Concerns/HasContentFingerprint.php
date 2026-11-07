<?php

namespace App\Models\Concerns;

use Illuminate\Support\Arr;

/**
 * 編集の画面を開いたときの「中身の印」を作る（記録・定期的な記録）。
 * 保存のときに今の中身と比べて、開いたあとにほかの画面で直されていたら上書きしない
 */
trait HasContentFingerprint
{
    /**
     * 直した日時は秒までしかなく、同じ秒に 2 回直されると見分けられないので、日時ではなく中身の列で比べる。
     *
     * - 直した日時（updated_at）と相手（partner_id）は入れない：相手が別の相手にまとめられると、この 2 つの列だけが変わるが、
     *   それは「直された」ではなく、まとめ先で保存する決まりのため。相手が別の画面で直されたかは、画面の側が
     *   開いたときの相手と比べて見分ける（ChoosesPartnerCategoryAccount::partnerChangedElsewhere）
     * - 比べる列は、画面で直せる列（$fillable）だけ：withCount などで足した列が混ざって、開いたときと保存のときで
     *   印がずれつづけ、保存がずっと止まるのを防ぐため
     * - 空（null）の列は入れない、列の順番はそろえる：作った直後のモデルと、データベースから読んだモデルでは、
     *   持っている列や並びがちがうことがあり、中身が同じなのに印がちがってしまうのを防ぐため
     */
    public function fingerprint(): string
    {
        // 失敗したときに、いつも同じ印（空の文字の md5）になって、変化に気づけなくなるのを防ぐため、例外にする
        return md5(json_encode($this->contentForCompare(), JSON_THROW_ON_ERROR));
    }

    /**
     * 保存しようとしている中身（列の名前 → 値）が、いまの中身と同じか（相手は入れない。fingerprint と同じ列でくらべる）。
     * 変更の画面から 2 回届いた 2 回目を見分けるのに使う（画面設計書 5.4。2026-11-07 に決定）
     *
     * @param  array<string, mixed>  $values
     */
    public function hasSameContent(array $values): bool
    {
        // いまの中身の写しに入れてみて、同じ決まり（型の変換）を通した形どうしでくらべる。
        // 数は、データベースから読むと文字で届くこともあるので、くらべる前にすべて文字にそろえる
        $text = function (array $content): array {
            array_walk_recursive($content, function (mixed &$value) {
                $value = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
            });

            return $content;
        };

        return $text($this->replicate()->fill($values)->contentForCompare()) === $text($this->contentForCompare());
    }

    /**
     * くらべる中身：画面で直せる列から相手を外し、空の列を外して、列の名前の順にそろえたもの
     *
     * @return array<string, mixed>
     */
    private function contentForCompare(): array
    {
        $content = array_filter(
            Arr::only($this->attributesToArray(), array_diff($this->getFillable(), ['partner_id'])),
            fn (mixed $value) => $value !== null,
        );
        ksort($content);

        return $content;
    }
}
