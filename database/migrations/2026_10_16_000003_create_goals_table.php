<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 貯金の目標の表（データベース設計書 3.10）。貯めた額は持たない（選んだ口座の今の残高を、そのまま使う）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goals', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            $table->unsignedInteger('target_amount');
            // 目標が使っている口座は削除できない（外部キーで止める。データベース設計書 3.10）。
            // unique：1 つの口座は 1 つの目標だけ（同じお金を 2 回数えないように）。2 つの画面から同時に保存しても重ならない
            $table->foreignId('account_id')->unique()->constrained();
            // 期限の月（その月の 1 日として持つ。その月の終わりまで）。空なら期限なし
            $table->date('deadline_month')->nullable();
            $table->datetimes();
        });

        // 目標額は 1 円以上（0 だと % の計算で 0 で割ってしまう）。アプリの入力チェックに加えて、データベースでも止める
        DB::statement('ALTER TABLE goals ADD CONSTRAINT goals_target_amount_positive CHECK (target_amount >= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('goals');
    }
};
