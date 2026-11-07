<?php

namespace Tests\Feature\ReviewSweep88;

use App\Models\Partner;
use App\Models\Record;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * イシュー #88：相手「その他」は守る（画面設計書 5.9、データベース設計書 3.4、要件定義書 5.4。2026-11-07 に決定）。
 * 削除・「使わない」・ほかの相手へのまとめ・名前の変更をしない。ほかの相手を「その他」にまとめるのは、できる
 */
class OtherPartnerGuardTest extends TestCase
{
    use RefreshDatabase;

    /** 文は「その他」の受け皿の趣旨（画面設計書 5.9）。欄の名前は設計書にないので、文で確かめる */
    private const REASON = '急ぐときの受け皿';

    private Partner $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->other = Partner::query()->where('name', 'その他')->firstOrFail();
    }

    private function errorText(): string
    {
        $errors = session('errors');

        return $errors === null ? '' : implode("\n", $errors->getBag('default')->all());
    }

    /** 止めた文には、受け皿の趣旨がある（エラーでも、知らせ warning でもよい） */
    private function assertReasonShown(): void
    {
        $shown = $this->errorText().' '.(string) session('warning');
        $this->assertStringContainsString(self::REASON, $shown);
    }

    public function test_p1_その他は_記録がなくても削除できない(): void
    {
        $this->delete("/partners/{$this->other->id}")->assertSessionMissing('status');

        $this->assertReasonShown();
        $this->assertModelExists($this->other);
    }

    public function test_p2_その他は_変更の保存で使わないにできない(): void
    {
        $this->put("/partners/{$this->other->id}", ['name' => 'その他', 'is_active' => '0'])->assertSessionMissing('status');

        $this->assertReasonShown();
        $this->assertTrue($this->other->refresh()->is_active);
    }

    public function test_p2_その他は_削除の小窓からも使わないにできない(): void
    {
        $this->patch("/partners/{$this->other->id}/deactivate")->assertSessionMissing('status');

        $this->assertReasonShown();
        $this->assertTrue($this->other->refresh()->is_active);
    }

    public function test_p3_その他は_ほかの相手にまとめられない(): void
    {
        $shop = Partner::factory()->create(['name' => 'コンビニ']);
        Record::factory()->count(2)->create(['partner_id' => $this->other->id]);

        $this->post("/partners/{$this->other->id}/merge", ['merge_target' => 'コンビニ'])->assertSessionMissing('status');

        $this->assertReasonShown();
        $this->assertModelExists($this->other);
        $this->assertSame(2, Record::query()->where('partner_id', $this->other->id)->count(), '記録は移らない');
        $this->assertSame(0, Record::query()->where('partner_id', $shop->id)->count());
    }

    public function test_p3_ほかの相手を_その他にまとめるのは_できる(): void
    {
        $typo = Partner::factory()->create(['name' => 'コンビ二']);
        Record::factory()->count(3)->create(['partner_id' => $typo->id]);

        $this->post("/partners/{$typo->id}/merge", ['merge_target' => 'その他'])
            ->assertRedirect('/partners')->assertSessionHas('status', 'まとめました');

        $this->assertModelMissing($typo);
        $this->assertSame(3, Record::query()->where('partner_id', $this->other->id)->count());
    }

    public function test_p4_その他は_名前を変えられない(): void
    {
        $this->put("/partners/{$this->other->id}", ['name' => 'そのほか', 'is_active' => '1'])->assertSessionMissing('status');

        $this->assertReasonShown();
        $this->assertSame('その他', $this->other->refresh()->name);
    }

    public function test_p4_その他は_名前を変えずに使うのまま保存するのは止めない(): void
    {
        $this->put("/partners/{$this->other->id}", ['name' => 'その他', 'is_active' => '1'])->assertSessionHasNoErrors();
        $this->assertSame('その他', $this->other->refresh()->name);
    }

    public function test_p4_ほかの相手を_その他という名前には変えられない_今までどおり名前の重なりで止まる(): void
    {
        $shop = Partner::factory()->create(['name' => 'コンビニ']);

        $this->put("/partners/{$shop->id}", ['name' => 'その他', 'is_active' => '1'])->assertSessionHasErrors('name');
        $this->assertSame('コンビニ', $shop->refresh()->name);
    }

    public function test_その他の変更の画面には_受け皿なので守られていることが出る(): void
    {
        $this->get("/partners/{$this->other->id}/edit")->assertOk()->assertSee(self::REASON);
    }

    public function test_ほかの相手の変更の画面には_その他の守りの文は出ない(): void
    {
        $shop = Partner::factory()->create(['name' => 'コンビニ']);

        $this->get("/partners/{$shop->id}/edit")->assertOk()->assertDontSee(self::REASON);
    }

    /**
     * P-5：「その他」がデータベースに無いときは、マイグレーションで入れ直す（データベース設計書 3.4）。
     * 入れ直すマイグレーションの名前は設計書にないので、2026-11-07 以降の日付で、相手の表に「その他」を入れる
     * マイグレーションを探して、up() を直接動かして確かめる（1 つも見つからなければ、まだ作っていない）
     *
     * @return list<Migration>
     */
    private function reinsertMigrations(): array
    {
        $found = [];
        foreach (glob(database_path('migrations/*.php')) ?: [] as $path) {
            if (strcmp(basename($path), '2026_11_07') < 0) {
                continue;
            }
            $source = (string) file_get_contents($path);
            if (str_contains($source, 'partners') && str_contains($source, 'その他')) {
                $migration = require $path;
                if ($migration instanceof Migration) {
                    $found[] = $migration;
                }
            }
        }

        return $found;
    }

    public function test_p5_マイグレーションは_その他が無ければ入れ直し_あれば2つにならない(): void
    {
        $migrations = $this->reinsertMigrations();
        $this->assertNotEmpty($migrations, '「その他」を入れ直すマイグレーション（2026_11_07 以降の日付）がまだ無い');

        // 手元のデータベースで「その他」が消えていた状態（記録からつながっていない）
        Partner::query()->where('name', 'その他')->delete();
        $this->assertSame(0, Partner::query()->where('name', 'その他')->count());

        foreach ($migrations as $migration) {
            $migration->up();
        }
        $this->assertSame(1, Partner::query()->where('name', 'その他')->count(), '無ければ入る');
        $this->assertTrue(Partner::query()->where('name', 'その他')->sole()->is_active);

        // もう一度流しても 1 つのまま
        foreach ($migrations as $migration) {
            $migration->up();
        }
        $this->assertSame(1, Partner::query()->where('name', 'その他')->count(), 'あれば何もしない');
    }

    public function test_p5_マイグレーションは_すでにあるその他を作り直さず同じ行のままにする(): void
    {
        $migrations = $this->reinsertMigrations();
        $this->assertNotEmpty($migrations, '「その他」を入れ直すマイグレーション（2026_11_07 以降の日付）がまだ無い');

        // 設計書は「無ければ入れ直す。すでにあれば何もしない」。あるものは、2 つにも作り直しにもしない
        $before = Partner::query()->where('name', 'その他')->sole()->id;
        foreach ($migrations as $migration) {
            $migration->up();
        }
        $this->assertSame($before, Partner::query()->where('name', 'その他')->sole()->id);
    }
}
