<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Record;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 2038 年問題（データベース設計書 1 章・要件定義書 10.1 の「1 回目のデプロイ」）。
 * TIMESTAMP や INT で秒を数える列は、2038年1月19日をこえる時刻が入らない。こえても保存できることを確かめる
 */
class Year2038Test extends TestCase
{
    use RefreshDatabase;

    public function test_2038年をこえても_作った日時と変えた日時が保存できる(): void
    {
        $this->travelTo(now()->setDate(2100, 1, 1)->setTime(9, 0));

        $user = User::factory()->create();
        $record = Record::factory()->create(['date' => '2100-01-01', 'account_id' => Account::factory()->create()->id]);

        $this->assertSame('2100-01-01 09:00:00', $user->fresh()?->created_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2100-01-01 09:00:00', $record->fresh()?->updated_at?->format('Y-m-d H:i:s'));
    }

    public function test_2038年をこえても_ログインの記録の時刻が保存できる(): void
    {
        // 2100年1月1日の時刻（1970年からの秒の数）。INT（2,147,483,647 まで）には入らない
        $seconds = 4102444800;

        DB::table('sessions')->insert(['id' => 'year-2100', 'payload' => '', 'last_activity' => $seconds]);

        $this->assertSame($seconds, (int) DB::table('sessions')->where('id', 'year-2100')->value('last_activity'));
    }
}
