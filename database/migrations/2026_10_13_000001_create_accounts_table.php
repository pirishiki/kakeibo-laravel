<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 口座の表（データベース設計書 3.2）
 * 取っておく額の 2 列は第 2 段階で画面に出す。表の形を設計書とそろえるため、先に作っておく
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            // 名前の同じ・ちがうは、日本語用の決まり（照合順序）で決める。濁点（ハハ／パパ）・ひらがなとカタカナ・大文字と小文字は別の名前、
            // 全角と半角の英数字（ＡＢ／AB）は同じ名前（データベース設計書 1 章）
            $table->string('name', 50)->collation('utf8mb4_ja_0900_as_cs_ks')->unique();
            $table->string('type', 20);
            $table->integer('initial_balance');
            $table->string('reserve_type', 20)->default('none');
            $table->integer('reserve_amount')->nullable();
            $table->boolean('is_active')->default(true);
            $table->datetimes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
