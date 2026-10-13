<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 記録の表（データベース設計書 3.5）。画面は記録のイシューで作る
 * 分類・相手・定期的な記録の表はまだないので、その 3 つを指す外部キーは、それぞれの表を作るときに足す
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('records', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('type', 20);
            $table->integer('amount');
            // 記録がある口座は削除できない（restrictOnDelete。データベース設計書 1 章）
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('to_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('partner_id')->nullable();
            $table->string('item')->nullable();
            $table->text('memo')->nullable();
            $table->unsignedBigInteger('recurring_id')->nullable();
            $table->date('recurring_month')->nullable();
            $table->timestamps();

            // 同じ予定の同じ月の分を 2 回記録しない（データベース設計書 3.5）
            $table->unique(['recurring_id', 'recurring_month']);
            // 口座ごと・日付までの残高を計算するときに速く探すため
            $table->index(['account_id', 'date']);
            $table->index(['to_account_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('records');
    }
};
