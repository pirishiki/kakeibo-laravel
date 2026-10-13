<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 最初の分類を入れておく（要件定義書 5.6 の順番。データベース設計書 3.3）
 * マイグレーションで入れるので、本番でも手元でも、表を作ったときに 1 回だけ入る
 */
return new class extends Migration
{
    private const NAMES = [
        'expense' => ['食費', '日用品', '交通費', '住まい', '水道・光熱費', '通信費', '自動車', 'ローン', '保険', '医療・健康', '衣服・美容', '趣味・娯楽', '交際費', '税金・社会保険', 'その他'],
        'income' => ['給与', '賞与', '臨時収入', 'その他'],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::NAMES as $kind => $names) {
            foreach ($names as $i => $name) {
                DB::table('categories')->insert([
                    'name' => $name,
                    'kind' => $kind,
                    'sort_order' => $i + 1,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('categories')->whereNull('parent_id')->whereIn('name', array_merge(...array_values(self::NAMES)))->delete();
    }
};
