<?php

namespace Tests\Feature\Partners;

use App\Models\Partner;
use App\Models\Record;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 要件定義書 10.1「相手の管理（No.7、画面設計書 5.9）」の受け入れ基準を確かめる。
 * 記録の登録で、相手の候補が最近使った順に出ることは、記録の画面を作るときに確かめる。
 */
class PartnerManageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->travelTo(now()->setDate(2026, 10, 13));
    }

    private function useOn(Partner $partner, string $date, int $times = 1): void
    {
        Record::factory()->count($times)->create(['partner_id' => $partner->id, 'date' => $date]);
    }

    public function test_ログインしないと相手の画面は開けない(): void
    {
        auth()->logout();

        $this->get('/partners')->assertRedirect('/login');
        $this->post('/partners', ['name' => 'コンビニ'])->assertRedirect('/login');
    }

    public function test_使い始めたときに相手その他が入っている(): void
    {
        $this->assertSame(['その他'], Partner::query()->pluck('name')->all());
        $this->get('/partners')->assertSeeInOrder(['まだ使っていない相手', 'その他', '＋ 相手を追加']);
    }

    public function test_一覧は最近使った順_まだ使っていない相手は追加した順_使わない相手はいちばん下(): void
    {
        $super = Partner::factory()->create(['name' => 'スーパー']);
        $conv = Partner::factory()->create(['name' => 'コンビニ']);
        $station = Partner::factory()->create(['name' => '駅']);
        $gym = Partner::factory()->create(['name' => '旧ジム', 'is_active' => false]);
        Partner::factory()->create(['name' => '本屋']);

        $this->useOn($super, '2026-10-08');
        $this->useOn($conv, '2026-10-02');
        $this->useOn($conv, '2026-10-09');
        $this->useOn($station, '2026-10-09');
        $this->useOn($gym, '2026-03-12');

        $this->get('/partners')->assertSeeInOrder([
            'コンビニ', '10月9日（金）',
            '駅', '10月9日（金）',
            'スーパー', '10月8日（木）',
            'まだ使っていない相手', 'その他', '本屋',
            '＋ 相手を追加',
            '使わない相手', '旧ジム', '3月12日（木）',
        ]);
    }

    public function test_今年でない日付には年をつける(): void
    {
        $old = Partner::factory()->create(['name' => '昔のお店']);
        $this->useOn($old, '2025-12-30');

        $this->get('/partners')->assertSee('2025年12月30日（火）');
    }

    public function test_名前で探すための名前が行についている(): void
    {
        // しぼりこみは画面の中（JavaScript）で行う。ここでは、探す欄と、行の名前があることを確かめる
        Partner::factory()->create(['name' => 'コンビニ']);

        $this->get('/partners')
            ->assertSee('placeholder="名前で探す"', false)
            ->assertSee('data-name="コンビニ"', false)
            ->assertSee('data-name="その他"', false);
    }

    public function test_相手を追加でき_ほかの相手と同じ名前はつけられない(): void
    {
        $this->post('/partners', ['name' => 'コンビニ'])->assertRedirect('/partners')->assertSessionHas('status', '保存しました');
        $this->assertTrue(Partner::query()->where('name', 'コンビニ')->firstOrFail()->is_active);

        $this->post('/partners', ['name' => 'コンビニ'])->assertSessionHasErrors(['name' => 'この名前は、もう使われています。']);
        // 打ちまちがいの「コンビ二」（ニが漢字の二）は、別の名前としてつけられる
        $this->post('/partners', ['name' => 'コンビ二'])->assertSessionHasNoErrors();
    }

    public function test_名前は必須で50文字まで(): void
    {
        $this->post('/partners', ['name' => ''])->assertSessionHasErrors(['name' => '名前を入れてください。']);
        $this->post('/partners', ['name' => str_repeat('あ', 51)])->assertSessionHasErrors(['name' => '名前は 50 文字以内で入れてください。']);
    }

    public function test_相手の名前と使うを直せる(): void
    {
        $partner = Partner::factory()->create(['name' => 'コンビ二']);

        $this->put("/partners/{$partner->id}", ['name' => 'コンビニ', 'is_active' => '0'])->assertRedirect('/partners');

        $partner->refresh();
        $this->assertSame('コンビニ', $partner->name);
        $this->assertFalse($partner->is_active);
    }

    public function test_ほかの相手にまとめると記録が移り_元の相手は削除される(): void
    {
        $typo = Partner::factory()->create(['name' => 'コンビ二']);
        $right = Partner::factory()->create(['name' => 'コンビニ']);
        $this->useOn($typo, '2026-10-02', 3);
        $this->useOn($right, '2026-10-09', 2);

        $this->get("/partners/{$typo->id}/edit")
            ->assertSee('コンビ二の記録 3 件を')
            ->assertSee('<option value="コンビニ">', false);

        $this->post("/partners/{$typo->id}/merge", ['merge_target' => 'コンビニ'])
            ->assertRedirect('/partners')
            ->assertSessionHas('status', 'まとめました');

        $this->assertModelMissing($typo);
        $this->assertSame(5, $right->records()->count());
    }

    public function test_まとめ先に自分と使わない相手とない相手は選べない(): void
    {
        $partner = Partner::factory()->create(['name' => 'コンビ二']);
        Partner::factory()->create(['name' => '旧ジム', 'is_active' => false]);

        $this->get("/partners/{$partner->id}/edit")
            ->assertDontSee('<option value="コンビ二">', false)
            ->assertDontSee('<option value="旧ジム">', false)
            ->assertSee('<option value="その他">', false);

        foreach (['コンビ二', '旧ジム', 'ないお店', ''] as $name) {
            $this->post("/partners/{$partner->id}/merge", ['merge_target' => $name])->assertSessionHasErrors('merge_target');
        }
        $this->post("/partners/{$partner->id}/merge", ['merge_target' => 'ないお店'])
            ->assertSessionHasErrors(['merge_target' => 'まとめ先は、出てくる相手から選んでください。']);
        $this->assertModelExists($partner);
    }

    public function test_確かめたあとでまとめ先が使わないになっていたらまとめない(): void
    {
        $typo = Partner::factory()->create(['name' => 'コンビ二']);
        $right = Partner::factory()->create(['name' => 'コンビニ']);
        $this->useOn($typo, '2026-10-02');

        // 別の画面で、まとめ先を「使わない」にしたあと
        Partner::query()->whereKey($right->id)->update(['is_active' => false]);

        $this->assertFalse($typo->mergeInto($right));
        $this->assertModelExists($typo);
        $this->assertSame(1, $typo->records()->count());
    }

    public function test_全角と半角の英数字は同じ名前として扱う(): void
    {
        Partner::factory()->create(['name' => 'ABストア']);

        $this->post('/partners', ['name' => 'ＡＢストア'])->assertSessionHasErrors('name');

        // まとめ先も、全角で打っても半角の相手が見つかる
        $typo = Partner::factory()->create(['name' => 'ABすとあ']);
        $this->post("/partners/{$typo->id}/merge", ['merge_target' => 'ＡＢストア'])->assertSessionHasNoErrors();
        $this->assertModelMissing($typo);
    }

    public function test_名前を変えずに保存できる(): void
    {
        $partner = Partner::factory()->create(['name' => 'コンビニ']);

        $this->put("/partners/{$partner->id}", ['name' => 'コンビニ', 'is_active' => '1'])->assertSessionHasNoErrors();
    }

    public function test_まとめ先にできる相手がないときはその文を出す(): void
    {
        Partner::query()->delete();
        $only = Partner::factory()->create(['name' => 'コンビニ']);

        $this->get("/partners/{$only->id}/edit")->assertSee('まとめ先にできる相手がありません')->assertDontSee('id="merge-form"', false);
    }

    public function test_相手がないときは案内を出す(): void
    {
        Partner::query()->delete();

        $this->get('/partners')->assertSee('まだ相手がありません')->assertSee('＋ 相手を追加');
    }

    public function test_記録がない相手は確認の小窓のあと削除できる(): void
    {
        $partner = Partner::factory()->create(['name' => '本屋']);

        $this->get("/partners/{$partner->id}/edit")->assertSee('「本屋」を削除しますか？');
        $this->delete("/partners/{$partner->id}")->assertRedirect('/partners')->assertSessionHas('status', '削除しました');
        $this->assertModelMissing($partner);
    }

    public function test_記録がある相手は削除できず_使わないにするかを聞かれる(): void
    {
        $partner = Partner::factory()->create(['name' => 'コンビニ']);
        $this->useOn($partner, '2026-10-09');

        $this->get("/partners/{$partner->id}/edit")->assertSee('記録があるので削除できません。『使わない』にしますか？');
        $this->delete("/partners/{$partner->id}")->assertSessionHasErrors(['delete' => '記録があるので削除できません']);
        $this->assertModelExists($partner);

        $this->patch("/partners/{$partner->id}/deactivate")->assertRedirect('/partners')->assertSessionHas('status', '「使わない」にしました');
        $this->assertFalse($partner->refresh()->is_active);
    }
}
