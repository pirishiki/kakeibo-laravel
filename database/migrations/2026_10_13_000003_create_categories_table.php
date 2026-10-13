<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 分類の表（データベース設計書 3.3）。大分類と小分類を 1 つの表に入れ、parent_id（親の分類）で 2 段を表す
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            // 名前の同じ・ちがうは、日本語用の決まり（照合順序）で決める（データベース設計書 1 章）
            $table->string('name', 50)->collation('utf8mb4_ja_0900_as_cs_ks');
            $table->string('kind', 20);
            // 小分類がある大分類は削除できない（restrictOnDelete）
            $table->foreignId('parent_id')->nullable()->constrained('categories')->restrictOnDelete();
            // 同じ段で同じ名前をつけないための列。大分類（parent_id が空）は 0 にする
            // （MySQL は、空どうしを「同じ」と見なさないので、parent_id のままでは大分類どうしの同じ名前を止められないため）
            $table->unsignedBigInteger('parent_key')->storedAs('coalesce(parent_id, 0)');
            $table->unsignedInteger('sort_order');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // 同じ段（同じ用の大分類どうし、同じ大分類の中の小分類どうし）で同じ名前はつけられない（画面設計書 5.8）
            $table->unique(['kind', 'parent_key', 'name']);
        });

        // 記録の分類を、分類の表につなぐ。記録がある分類は削除できない
        Schema::table('records', function (Blueprint $table) {
            $table->foreign('category_id')->references('id')->on('categories')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('records', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
        });
        Schema::dropIfExists('categories');
    }
};
