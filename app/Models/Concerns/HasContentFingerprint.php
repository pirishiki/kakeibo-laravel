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
        $content = array_filter(
            Arr::only($this->attributesToArray(), array_diff($this->getFillable(), ['partner_id'])),
            fn (mixed $value) => $value !== null,
        );
        ksort($content);

        // 失敗したときに、いつも同じ印（空の文字の md5）になって、変化に気づけなくなるのを防ぐため、例外にする
        return md5(json_encode($content, JSON_THROW_ON_ERROR));
    }
}
