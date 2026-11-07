<?php

namespace Tests\Feature\ReviewSweep88;

use App\Enums\CategoryKind;
use App\Models\Account;
use App\Models\Category;
use App\Models\Record;
use App\Models\Recurring;
use Database\Seeders\SampleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * イシュー #88：ためしのデータの category() が、見つからない分類名でも、全体がエラーで止まらず、
 * その行だけ飛ばして知らせる（手元のデータベースで最初の分類を消していたり名前を変えていたりしても、入れられるように）
 */
class SampleSeederMissingCategoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 14)->setTime(12, 0));
    }

    public function test_記録で使う分類が無くても_全体は止まらず_その行だけ飛ばして知らせる(): void
    {
        // 「交通費」は、記録 2 行（Suica の 210 円 ×2）で使う。手元で消していた
        Category::query()->whereNull('parent_id')->where('kind', CategoryKind::Expense)->where('name', '交通費')->delete();

        $this->artisan('db:seed', ['--class' => SampleSeeder::class])
            ->expectsOutputToContain('交通費')
            ->assertSuccessful();

        // 全体が止まらず、ほかは入る（口座 5 つ・定期的な記録 6 つ・記録は 12 のうち交通費の 2 行を飛ばした 10）
        $this->assertSame(5, Account::query()->count());
        $this->assertSame(6, Recurring::query()->count());
        $this->assertSame(10, Record::query()->count());
    }

    public function test_定期的な記録で使う分類が無くても_全体は止まらず_その行だけ飛ばして知らせる(): void
    {
        // 「賞与」は、定期的な記録 1 つ（賞与）だけが使う
        Category::query()->whereNull('parent_id')->where('kind', CategoryKind::Income)->where('name', '賞与')->delete();

        $this->artisan('db:seed', ['--class' => SampleSeeder::class])
            ->expectsOutputToContain('賞与')
            ->assertSuccessful();

        $this->assertSame(12, Record::query()->count());
        $this->assertSame(5, Recurring::query()->count());
        $this->assertFalse(Recurring::query()->where('name', '賞与')->exists());
    }
}
