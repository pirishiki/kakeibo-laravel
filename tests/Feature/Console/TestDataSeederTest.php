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

        // 種類ごとの決まり（データベース設計書 3 章）を守る。画面のチェックを通らずに入れるので、ここで確かめる
        $this->assertSame(0, Record::query()->whereIn('type', ['income', 'expense'])
            ->where(fn ($q) => $q->whereNull('partner_id')->orWhereNull('category_id')->orWhereNotNull('to_account_id'))->count(), '支出・収入は相手と分類があり、どこへがない');
        $this->assertSame(0, Record::query()->where('type', 'transfer')
            ->where(fn ($q) => $q->whereNotNull('partner_id')->orWhereNotNull('category_id')->orWhereNull('to_account_id')->orWhereColumn('account_id', 'to_account_id'))->count(), '移動は相手と分類がなく、どこへが別の口座');
        $this->assertSame(0, Record::query()->where('type', 'adjustment')->count(), '調整は入れない');
        $this->assertSame(0, Record::query()->where('amount', '<', 1)->count(), '金額は 1 以上');
        $this->assertSame(0, Record::query()->where('type', '!=', 'expense')->whereNotNull('item')->count(), '品物は支出だけ');
        $this->assertSame(0, Record::query()->whereNotNull('recurring_id')->count(), '定期的な記録から作った記録はない');
        $this->assertSame(0, Record::query()->join('categories', 'categories.id', '=', 'records.category_id')
            ->whereColumn('categories.kind', '!=', 'records.type')->count(), '収入には収入用、支出には支出用の分類');

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
