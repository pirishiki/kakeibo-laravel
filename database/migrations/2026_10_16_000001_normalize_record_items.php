<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 今までに入れた記録の品物も、半角カナを全角カナにそろえる（Record::normalizeItem。画面設計書 5.3）。
 * これからの記録は保存するときにそろえるので、ここでは前に入れたものだけを 1 回直す。
 * 半角と全角のカナが混ざった品物（ﾊﾟﾝケーキ）も、「パンケーキ」で探して見つかるようにするため
 */
return new class extends Migration
{
    public function up(): void
    {
        // 100 件ずつ読んで、形が変わるものだけを書き直す。日時（updated_at）は変えない（直したのは文字の形だけなので）
        DB::table('records')->whereNotNull('item')->select(['id', 'item'])->orderBy('id')
            ->chunkById(100, function ($rows) {
                foreach ($rows as $row) {
                    // Record::normalizeItem と同じそろえ方。あとでモデルを変えても、このマイグレーションの動きは変わらないように、ここに書く
                    $item = mb_convert_kana($row->item, 'KV');
                    if ($item !== $row->item) {
                        DB::table('records')->where('id', $row->id)->update(['item' => $item]);
                    }
                }
            });
    }

    public function down(): void
    {
        // 元の半角カナには戻せない（どの文字が半角だったかを残していないため）。戻さなくても、品物の読み方は同じ
    }
};
