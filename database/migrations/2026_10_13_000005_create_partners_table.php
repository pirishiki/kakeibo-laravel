<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 相手の表（データベース設計書 3.4）と、最初の相手「その他」（要件定義書 5.6）
 * 「その他」は、相手がはっきりしないときや急いでいるときに選び、あとで記録を直せるようにするためのもの
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partners', function (Blueprint $table) {
            $table->id();
            // 名前の同じ・ちがうは、日本語用の決まり（照合順序）で決める（データベース設計書 1 章）
            $table->string('name', 50)->collation('utf8mb4_ja_0900_as_cs_ks')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 記録の相手を、相手の表につなぐ。記録がある相手は削除できない
        Schema::table('records', function (Blueprint $table) {
            $table->foreign('partner_id')->references('id')->on('partners')->restrictOnDelete();
        });

        DB::table('partners')->insert(['name' => 'その他', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('records', function (Blueprint $table) {
            $table->dropForeign(['partner_id']);
        });
        Schema::dropIfExists('partners');
    }
};
