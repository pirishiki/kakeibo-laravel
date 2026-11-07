<?php

use App\Models\Partner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 相手「その他」が無くなっていたら、入れ直す（データベース設計書 3.4。2026-11-07 に決定。イシュー #88）。
 * 「その他」は急ぐときの受け皿で、いつも選べる状態でなければならない。守る決まり（削除・「使わない」・まとめる・名前の変更をしない）を
 * 入れる前に、手元のデータベースで消したり名前を変えたりしていたときのため。
 * すでにあれば何もしない（何回流しても 1 つのまま。もとの行を作り直さないので、記録とのつながりも変わらない）
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('partners')->where('name', Partner::OTHER_NAME)->exists()) {
            return;
        }

        // 確かめてから入れるまでに、ほかで「その他」ができていても、名前のユニーク制約で 2 つにはならない（そのときは入れずに終わる）
        DB::table('partners')->insertOrIgnore(['name' => Partner::OTHER_NAME, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** 入れ直した「その他」は、もとからあったものと見分けられないので、戻すときも消さない */
    public function down(): void {}
};
