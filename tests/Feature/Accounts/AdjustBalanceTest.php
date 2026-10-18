<?php

namespace Tests\Feature\Accounts;

use App\Enums\AccountType;
use App\Enums\CategoryKind;
use App\Enums\RecordType;
use App\Livewire\AdjustBalance;
use App\Livewire\RecordForm;
use App\Models\Account;
use App\Models\Category;
use App\Models\Partner;
use App\Models\Record;
use App\Models\User;
use App\Support\FormInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 要件定義書 10.1 の残高を合わせる（No.4、画面設計書 5.7）の項目を確かめる
 */
class AdjustBalanceTest extends TestCase
{
    use RefreshDatabase;

    private Account $cash;

    private Category $food;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->travelTo(now()->setDate(2026, 10, 9)->setTime(12, 0));
        $this->cash = Account::factory()->create(['name' => '財布', 'type' => AccountType::Cash, 'initial_balance' => 10000]);
        $this->food = Category::query()->whereNull('parent_id')->where('kind', CategoryKind::Expense)->where('name', '食費')->firstOrFail();
    }

    private function expense(string $date, int $amount, ?Account $account = null): Record
    {
        return Record::factory()->create(['date' => $date, 'type' => RecordType::Expense, 'amount' => $amount,
            'account_id' => ($account ?? $this->cash)->id, 'category_id' => $this->food->id,
            'partner_id' => Partner::factory()->create()->id]);
    }

    private function adjust(): Testable
    {
        return Livewire::test(AdjustBalance::class, ['account' => $this->cash]);
    }

    public function test_口座の管理から開ける(): void
    {
        $this->expense('2026-10-01', 2000);

        $this->get(route('accounts.edit', $this->cash))->assertSee(route('accounts.adjust', $this->cash));
        $this->get(route('accounts.adjust', $this->cash))->assertOk()->assertSeeLivewire(AdjustBalance::class)
            ->assertSee('財布の残高を合わせる');
    }

    public function test_実際の金額を入れると差がすぐに出る_マイナスは赤(): void
    {
        $this->expense('2026-10-01', 2000);

        $this->adjust()
            ->assertSet('appBalance', 8000)
            ->set('actual', '8,500')
            ->assertSet('difference', 500)
            ->assertSeeHtmlInOrder(['<span>', '+', '<span class="">500</span>'])
            ->set('actual', '７５００')
            ->assertSet('difference', -500)
            ->assertSeeHtml('<span class="text-danger">-500</span>');
    }

    public function test_差が0のときは_ずれはありませんと出て保存できない(): void
    {
        $this->adjust()
            ->set('actual', '10000')
            ->assertSee('ずれはありません')
            ->assertSeeHtml('disabled')
            ->call('save')
            ->assertHasErrors('actual');

        $this->assertSame(0, Record::query()->count());
    }

    public function test_保存すると差の金額で調整の記録ができ_残高が実際の金額になる(): void
    {
        $this->expense('2026-10-01', 2000);
        session()->setPreviousUrl(route('accounts.index'));

        $this->adjust()
            ->set('actual', '8500')
            ->set('memo', '財布を数えた')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('accounts.index'));

        $record = Record::query()->where('type', RecordType::Adjustment)->sole();
        $this->assertSame(500, $record->amount);
        $this->assertSame('2026-10-09', $record->date->toDateString());
        $this->assertSame('財布を数えた', $record->memo);
        $this->assertSame(8500, $this->cash->balance());
        $this->assertSame(10000, $this->cash->refresh()->initial_balance, '最初の残高は書きかえない');
        $this->assertSame('保存しました', session('status'));
    }

    public function test_マイナスの実際の金額も入れられる(): void
    {
        $card = Account::factory()->create(['name' => 'カード', 'type' => AccountType::CreditCard, 'initial_balance' => 0]);
        $this->expense('2026-10-01', 3000, $card);

        Livewire::test(AdjustBalance::class, ['account' => $card])
            ->set('actual', '－3,200')
            ->assertSet('difference', -200)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(-3200, $card->balance());
    }

    public function test_前の日にすると_その日の終わりまでの残高とくらべ_その日の残高が実際の金額になる(): void
    {
        // 6日の終わりまでの残高は 9,200。8日の支出は 6日の残高に入らない
        $this->expense('2026-10-05', 800);
        $this->expense('2026-10-08', 1000);

        $this->adjust()
            ->set('date', '2026-10-06')
            ->assertSet('appBalance', 9200)
            ->set('actual', '8500')
            ->assertSet('difference', -700)
            ->call('save')
            ->assertHasNoErrors();

        $record = Record::query()->where('type', RecordType::Adjustment)->sole();
        $this->assertSame('2026-10-06', $record->date->toDateString());
        $this->assertSame(8500, $this->cash->balance(now()->setDate(2026, 10, 6)));
        $this->assertSame(9200, $this->cash->balance(now()->setDate(2026, 10, 5)), '調整の日付より前の日の残高は変わらない');
    }

    public function test_日付は今日が入っていて_先の日付は選べない(): void
    {
        $this->adjust()
            ->assertSet('date', '2026-10-09')
            ->set('date', '2026-10-10')
            ->set('actual', '1')
            ->call('save')
            ->assertHasErrors(['date' => 'before_or_equal']);
    }

    public function test_金額の上限と_数字でないもの(): void
    {
        $max = FormInput::MAX_AMOUNT;
        $this->adjust()->set('actual', (string) ($max + 1))->call('save')->assertHasErrors(['actual' => 'max']);
        $this->adjust()->set('actual', 'abc')->call('save')->assertHasErrors(['actual' => 'integer']);
        $this->adjust()->set('actual', '')->call('save')->assertHasErrors(['actual' => 'required']);
    }

    public function test_調整は月の合計に入らない(): void
    {
        $this->adjust()->set('actual', '12000')->call('save')->assertHasNoErrors();

        $this->get('/records')->assertSeeInOrder(['収入', '0', '支出', '0', '差', '0']);
    }

    public function test_最近の記録が10件_新しい順に出て_移動のどこへもふくむ(): void
    {
        $bank = Account::factory()->create(['name' => '銀行']);
        foreach (range(1, 11) as $day) {
            $this->expense(sprintf('2026-09-%02d', $day), $day);
        }
        Record::factory()->create(['date' => '2026-10-02', 'type' => RecordType::Transfer, 'amount' => 30000,
            'account_id' => $bank->id, 'to_account_id' => $this->cash->id]);
        $this->expense('2026-10-01', 999, $bank);

        $component = $this->adjust();
        $records = $component->get('recentRecords');

        $this->assertCount(10, $records);
        $this->assertSame(RecordType::Transfer, $records->first()->type, '移動の「どこへ」もふくむ');
        $this->assertFalse($records->contains('amount', 999), 'ほかの口座だけの記録は出ない');
        $this->assertFalse($records->contains('amount', 1), 'いちばん古い 1 件は出ない');
        $component->assertSee('銀行 → 財布')->assertSee(route('records.edit', $records->first()));
    }

    public function test_つけ忘れを記録すると_口座はそのままで_この画面に戻る(): void
    {
        $other = Account::factory()->create(['name' => '銀行']);
        $shop = Partner::factory()->create(['name' => 'コンビニ']);
        // コンビニの前回の口座は銀行。ふつうに相手を選ぶと銀行が入る
        Record::factory()->create(['date' => '2026-10-01', 'type' => RecordType::Expense, 'amount' => 100,
            'account_id' => $other->id, 'category_id' => $this->food->id, 'partner_id' => $shop->id]);
        session()->setPreviousUrl(route('accounts.index'));
        $adjustUrl = route('accounts.adjust', ['account' => $this->cash, 'back' => route('accounts.index')]);

        $this->adjust()->assertSee(e(route('records.create', ['account' => $this->cash->id, 'keep_account' => 1, 'back' => $adjustUrl])), false);

        Livewire::withQueryParams(['account' => (string) $this->cash->id, 'keep_account' => '1', 'back' => $adjustUrl])
            ->test(RecordForm::class)
            ->assertSet('accountId', $this->cash->id)
            ->set('amount', '680')
            ->set('partnerName', 'コンビニ')
            ->call('selectPartner', $shop->id)
            ->assertSet('accountId', $this->cash->id)
            ->assertSet('categoryId', $this->food->id)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect($adjustUrl);

        // 戻ってきた画面は、最初に開く前の画面（口座の管理）を覚えている
        Livewire::withQueryParams(['back' => route('accounts.index')])
            ->test(AdjustBalance::class, ['account' => $this->cash])
            ->assertSet('backUrl', route('accounts.index'))
            ->assertSet('appBalance', 10000 - 680);
    }

    public function test_差が記録の金額の上限をこえるときは_保存できない(): void
    {
        $this->cash->update(['initial_balance' => -FormInput::MAX_AMOUNT]);

        $this->adjust()
            ->set('actual', (string) FormInput::MAX_AMOUNT)
            ->assertSee('差が大きすぎます')
            ->call('save')
            ->assertHasErrors('actual');

        $this->assertSame(0, Record::query()->count());
    }

    public function test_開いたあとに使わないにされた口座には保存しない(): void
    {
        $component = $this->adjust()->set('actual', '10500');
        $this->cash->update(['is_active' => false]);

        // エラーの画面にせず、理由を出す（「使わない」口座はいつも 0 円のまま）
        $component->call('save')->assertHasErrors('actual')->assertSee('この口座は、ほかの画面で『使わない』にされたので、合わせられません。');
        $this->assertSame(0, Record::query()->count());
    }

    public function test_2つの画面から同じ口座を合わせても_二重に調整しない(): void
    {
        $first = $this->adjust()->set('actual', '10500');
        $second = $this->adjust()->set('actual', '10500');

        $first->call('save')->assertHasNoErrors();
        $second->call('save')->assertHasErrors('actual');
        $this->assertSame(1, Record::query()->count());
        $this->assertSame(10500, $this->cash->balance());
    }

    public function test_プラスや日本語入力のマイナスで打っても受けとる(): void
    {
        $this->adjust()->set('actual', '+10,500')->assertSet('difference', 500);
        $this->adjust()->set('actual', 'ー500')->assertSet('difference', -10500);
    }

    public function test_最近の記録から直したあとも_最初に開く前の画面を覚えている(): void
    {
        $record = $this->expense('2026-10-01', 2000);
        $adjustUrl = route('accounts.adjust', ['account' => $this->cash, 'back' => route('accounts.edit', $this->cash)]);
        session()->setPreviousUrl(route('accounts.edit', $this->cash));

        // 行のリンクに、この画面（戻る先つき）を back としてつける
        $this->adjust()->assertSee(e(route('records.edit', ['record' => $record, 'back' => $adjustUrl])), false);

        Livewire::withQueryParams(['back' => $adjustUrl])
            ->test(RecordForm::class, ['record' => $record])
            ->set('memo', '直した')
            ->call('save')
            ->assertRedirect($adjustUrl);
    }

    public function test_つけ忘れの登録で_保存して続けて入力しても_種類を変えても_口座は変わらない(): void
    {
        $bank = Account::factory()->create(['name' => '銀行']);
        Record::factory()->create(['date' => '2026-10-01', 'type' => RecordType::Income, 'amount' => 100, 'account_id' => $bank->id]);
        $adjustUrl = route('accounts.adjust', ['account' => $this->cash, 'back' => route('accounts.index')]);

        Livewire::withQueryParams(['account' => (string) $this->cash->id, 'keep_account' => '1', 'back' => $adjustUrl])
            ->test(RecordForm::class)
            ->set('type', 'income')
            ->assertSet('accountId', $this->cash->id)
            ->set('type', 'expense')
            ->set('amount', '100')
            ->set('partnerName', 'コンビニ')
            ->set('categoryId', $this->food->id)
            ->call('save', true)
            ->assertRedirect(route('records.create', [
                'type' => 'expense', 'date' => '2026-10-09', 'account' => $this->cash->id, 'keep_account' => 1, 'back' => $adjustUrl,
            ]));
    }

    public function test_口座を渡さずに_keep_accountだけ渡しても使わない(): void
    {
        Livewire::withQueryParams(['keep_account' => '1'])->test(RecordForm::class)->assertSet('keepAccount', false);
    }

    public function test_戻る先によそのサイトは使わない(): void
    {
        foreach (['https://evil.example/', 'http://localhost//evil.example', 'javascript:alert(1)'] as $back) {
            Livewire::withQueryParams(['back' => $back])
                ->test(AdjustBalance::class, ['account' => $this->cash])
                ->assertSet('backUrl', route('home'));
        }
    }

    public function test_長すぎる戻る先は条件を外して_記録の画面へのリンクが長くなりすぎない(): void
    {
        // 2 回エンコードすると上限（5,800 字）をこえる戻る先は、条件を外す（記号や日本語の生の字は、エンコードで 3 倍になる）
        foreach (['?x='.rawurlencode(str_repeat('あ', 300)), '?x='.str_repeat('/', 1200), '?x='.str_repeat('あ', 400)] as $query) {
            Livewire::withQueryParams(['back' => url('/accounts').$query])
                ->test(AdjustBalance::class, ['account' => $this->cash])
                ->assertSet('backUrl', url('/accounts'));
        }

        // 戻る先がいちばん長いとき（どの形でも）、記録の画面へのリンク（戻る先が入れ子になる）は 8 KB に入り、
        // 記録の画面でも戻る先として使える（条件が外れない）
        Record::factory()->create(['account_id' => $this->cash->id, 'date' => today()]);
        $base = url('/accounts').'?x=';
        // 2 回エンコードしたときの 1 つ分の長さ：a は 1 字、/ は %252F の 5 字、%E3%81%82 は %2525E3%252581%252582 の 21 字
        foreach (['a' => 1, '/' => 5, '%E3%81%82' => 21] as $unit => $growth) {
            $longest = $base.str_repeat($unit, intdiv(5800 - strlen(rawurlencode(rawurlencode($base))), $growth));
            $component = Livewire::withQueryParams(['back' => $longest])->test(AdjustBalance::class, ['account' => $this->cash])
                ->assertSet('backUrl', $longest);
            preg_match_all('#href="([^"]*/records/[^"]*)"#', $component->html(), $links);
            $this->assertCount(2, $links[1], '最近の記録の行と、つけ忘れを記録する');
            foreach ($links[1] as $link) {
                $link = html_entity_decode($link);
                $this->assertLessThan(8000, strlen($link));
                parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
                Livewire::withQueryParams(['back' => $query['back']])->test(RecordForm::class)->assertSet('backUrl', $query['back']);
            }
        }
    }

    public function test_戻る先は画面の側から変えられない(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        $this->adjust()->set('backUrl', 'https://evil.example/');
    }

    public function test_メモは1000文字まで(): void
    {
        $this->adjust()->set('actual', '1')->set('memo', str_repeat('あ', 1001))->call('save')->assertHasErrors(['memo' => 'max']);
    }

    public function test_先の日付の記録が理由のときは_残高を合わせるのリンクを出さない(): void
    {
        $this->cash->update(['initial_balance' => 0]);
        $this->expense('2026-10-20', 100);

        $this->patch(route('accounts.update', $this->cash), ['name' => '財布', 'type' => 'cash', 'is_active' => '0']);
        $this->get(route('accounts.edit', $this->cash))
            ->assertSee('先の日付の記録で残高が変わる')
            ->assertSeeHtml('先の日付の記録で残高が変わるので、『使わない』にできません</div>');
    }

    public function test_使わない口座は合わせられない(): void
    {
        $this->cash->update(['is_active' => false]);

        // 開いたままの古い画面のリンクから来ても、エラーの画面ではなく、口座の変更の画面で理由を出す
        $this->get(route('accounts.adjust', $this->cash))
            ->assertRedirect(route('accounts.edit', $this->cash))
            ->assertSessionHas('warning', 'この口座は「使わない」になっているので、残高を合わせられません。');
    }

    public function test_合わせる口座は画面の側から変えられない(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        $this->adjust()->set('accountId', Account::factory()->create()->id);
    }
}
