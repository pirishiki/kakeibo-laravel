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
            $table->string('name', 50)->unique();
            $table->string('type', 20);
            $table->integer('initial_balance');
            $table->string('reserve_type', 20)->default('none');
            $table->integer('reserve_amount')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
