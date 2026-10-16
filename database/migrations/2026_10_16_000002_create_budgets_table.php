<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 予算の表（データベース設計書 3.9）。ふだんの予算（start_month〜end_month）と、特別な月だけの予算（month）の 2 種類を入れる
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            // 記録のない分類を削除したら、その分類の予算もいっしょに消す（cascadeOnDelete。要件定義書 5.4）
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            // 月は、その月の 1 日として持つ。month が入っていれば特別な月、空ならふだんの予算
            $table->date('month')->nullable();
            $table->date('start_month')->nullable();
            $table->date('end_month')->nullable();
            $table->unsignedInteger('amount');
            $table->datetimes();

            // 同じ分類・同じ特別な月の予算は 1 つだけ。ふだんの予算（month が空）の重なりは、空どうしを「同じ」と見なさないので
            // データベースでは止められない。アプリで分類の行をロックしてから確かめる（app/Support/Budgets.php）
            $table->unique(['category_id', 'month']);
        });

        // 予算は 1 円以上（0 だと % の計算で 0 で割ってしまう）。アプリの入力チェックに加えて、データベースでも止める
        DB::statement('ALTER TABLE budgets ADD CONSTRAINT budgets_amount_positive CHECK (amount >= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('budgets');
    }
};
