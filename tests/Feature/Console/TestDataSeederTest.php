<?php

namespace Tests\Feature\Console;

use App\Models\Account;
use App\Models\Category;
use App\Models\Partner;
use App\Models\Record;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * デプロイした環境に入れるテスト用のデータ（database/seeders/TestDataSeeder.php）を確かめる
 */
class TestDataSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_口座5_分類20_相手50_記録1万件が_今日までの5年分に入る(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(12, 0));

        $this->seed(TestDataSeeder::class);

        $this->assertSame(5, Account::query()->count());
        $this->assertSame(20, Category::query()->count());
        $this->assertSame(50, Partner::query()->count());
        $this->assertSame(TestDataSeeder::RECORDS, Record::query()->count());
        $this->assertSame('2021-11-01', Record::query()->min('date'));
        $this->assertSame('2026-10-15', Record::query()->max('date'), '今日より先の記録は入れない');

        // 残高が、ふつうに使っている家計簿らしい範囲におさまる（現金・Suica がマイナスのまま増えつづけない）
        foreach (Account::balances() as $id => $balance) {
            $this->assertGreaterThan(-100000, $balance, Account::query()->find($id)?->name.' の残高');
        }
    }

    public function test_口座か記録がもう入っているときは入れない(): void
    {
        Account::factory()->create();

        $this->seed(TestDataSeeder::class);

        $this->assertSame(1, Account::query()->count());
        $this->assertSame(0, Record::query()->count());
    }
}
