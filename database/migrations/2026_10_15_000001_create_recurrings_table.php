<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 定期的な記録の表（データベース設計書 3.6）と、その月ごとの金額（3.7）・今回はなし（3.8）の表
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurrings', function (Blueprint $table) {
            $table->id();
            // 同じ名前の予定も 2 つ登録できる（「金額を変えて続きを作る」のため。画面設計書 5.10）ので、ユニークにしない
            $table->string('name', 50);
            $table->string('type', 20);
            // 1〜28、または 31（＝月末）
            $table->unsignedTinyInteger('day');
            $table->string('shift', 20);
            // 選んだ月だけのときの月（例：[6, 12]）。空なら毎月
            $table->json('months')->nullable();
            $table->boolean('is_fixed');
            $table->integer('amount')->nullable();
            $table->integer('estimated_amount')->nullable();
            // 定期的な記録が使っている口座・分類・相手は削除できない（restrictOnDelete。要件定義書 5.4）
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('to_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('partner_id')->nullable()->constrained()->restrictOnDelete();
            // 月は、その月の 1 日として持つ
            $table->date('start_month');
            $table->date('end_month')->nullable();
            $table->boolean('is_active')->default(true);
            $table->datetimes();
        });

        // 記録を、どの定期的な記録から作られたかでつなぐ。記録がある定期的な記録は削除できない（4 章の 9）
        Schema::table('records', function (Blueprint $table) {
            $table->foreign('recurring_id')->references('id')->on('recurrings')->restrictOnDelete();
        });

        // 月ごとの金額と今回はなしは、予定を削除したら、いっしょに消す（cascadeOnDelete。要件定義書 10.2）
        Schema::create('recurring_amounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recurring_id')->constrained()->cascadeOnDelete();
            $table->date('month');
            $table->integer('amount');
            $table->datetimes();

            // 同じ予定の同じ月に、金額は 1 つだけ（データベース設計書 3.7）
            $table->unique(['recurring_id', 'month']);
        });

        Schema::create('recurring_skips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recurring_id')->constrained()->cascadeOnDelete();
            $table->date('month');
            $table->datetimes();

            $table->unique(['recurring_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_skips');
        Schema::dropIfExists('recurring_amounts');
        Schema::table('records', function (Blueprint $table) {
            $table->dropForeign(['recurring_id']);
        });
        Schema::dropIfExists('recurrings');
    }
};
