<?php

namespace Tests\Feature\Console;

use App\Enums\CategoryKind;
use App\Enums\RecordType;
use App\Models\Account;
use App\Models\Category;
use App\Models\Partner;
use App\Models\Record;
use App\Models\Recurring;
use Database\Seeders\SampleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ためしのデータ（database/seeders/SampleSeeder.php）を確かめる
 */
class SampleSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_今月と先月の記録が_種類ごとに入る(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 14)->setTime(12, 0));

        $this->seed(SampleSeeder::class);

        $this->assertSame(4, Account::query()->count());
        $old = Account::query()->where('name', '古い財布')->sole();
        $this->assertFalse($old->is_active, '「使わない」口座もある');
        $this->assertSame(0, $old->balance(), '「使わない」にできるのは残高 0 円の口座だけ');
        $this->assertSame(12, Record::query()->count());
        foreach (RecordType::cases() as $type) {
            $this->assertTrue(Record::query()->where('type', $type)->exists(), $type->value.' がある');
        }
        $this->assertSame(9, Record::query()->whereBetween('date', ['2026-10-01', '2026-10-31'])->count());
        $this->assertSame(3, Record::query()->whereBetween('date', ['2026-09-01', '2026-09-30'])->count());

        // 定期的な記録：一覧の上のまとまりに 4 つ、下のまとまり（終わったもの・使わないもの）に 2 つ
        $this->assertSame(6, Recurring::query()->count());
        $this->assertSame(4, Recurring::query()->running()->count());
        // 最初の月は、記録する日がまだ来ていない月。10月14日なので、給料（10月23日）は 10 月、Suica へ移す（10月1日）は 11 月から
        $this->assertSame('2026-10-01', Recurring::query()->where('name', '給料')->sole()->start_month->toDateString());
        $this->assertSame('2026-11-01', Recurring::query()->where('name', 'Suica へ移す')->sole()->start_month->toDateString());
    }

    public function test_月のはじめに動かしても_今日より先の記録はできない(): void
    {
        $this->travelTo(now()->setDate(2026, 3, 1)->setTime(12, 0));

        $this->seed(SampleSeeder::class);

        $this->assertSame(0, Record::query()->where('date', '>', '2026-03-01')->count());
        $this->assertSame(3, Record::query()->whereBetween('date', ['2026-02-01', '2026-02-28'])->count(), '先月が短い月でも先月に入る');
    }

    public function test_月末に動かしても_先月の記録は先月に入る(): void
    {
        // 3月31日の 1 か月前をそのまま数えると「2月31日」＝3月3日になってしまう
        $this->travelTo(now()->setDate(2026, 3, 31)->setTime(12, 0));

        $this->seed(SampleSeeder::class);

        $this->assertSame(3, Record::query()->whereBetween('date', ['2026-02-01', '2026-02-28'])->count());
        $this->assertSame(9, Record::query()->whereBetween('date', ['2026-03-01', '2026-03-31'])->count());
    }

    public function test_同じ名前の使わない相手と小分類があれば_使うに戻して使う(): void
    {
        $food = Category::query()->whereNull('parent_id')->where('kind', CategoryKind::Expense)->where('name', '食費')->firstOrFail();
        $eatOut = Category::factory()->childOf($food)->create(['name' => '外食', 'is_active' => false]);
        $cafe = Category::factory()->childOf($food)->create(['name' => 'カフェ']);
        $station = Partner::factory()->create(['name' => '駅', 'is_active' => false]);

        $this->seed(SampleSeeder::class);

        $this->assertTrue($eatOut->refresh()->is_active);
        $this->assertGreaterThan($cafe->sort_order, $eatOut->sort_order, '「使う」に戻した小分類は、同じ段のいちばん下');
        $this->assertTrue($station->refresh()->is_active);
        $this->assertSame(1, Category::query()->where('name', '外食')->count(), '2 つめは作らない');
        $this->assertSame(2, Record::query()->where('partner_id', $station->id)->count());
    }

    public function test_口座がもうあるときは入れずに止める(): void
    {
        Account::factory()->create();

        $this->artisan('db:seed', ['--class' => SampleSeeder::class])
            ->expectsOutputToContain('口座か記録がもう入っている');

        $this->assertSame(1, Account::query()->count());
        $this->assertSame(0, Record::query()->count());
    }

    public function test_本番では入れない(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('db:seed', ['--class' => SampleSeeder::class, '--force' => true])
            ->expectsOutputToContain('本番では');

        $this->assertSame(0, Account::query()->count());
    }
}
