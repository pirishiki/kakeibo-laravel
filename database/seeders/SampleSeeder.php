<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Enums\CategoryKind;
use App\Enums\RecordType;
use App\Enums\RecurringShift;
use App\Enums\ReserveType;
use App\Models\Account;
use App\Models\Category;
use App\Models\Partner;
use App\Models\Record;
use App\Models\Recurring;
use App\Models\RecurringSkip;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 手元で画面を確かめるための、ためしのデータ（php artisan db:seed --class=SampleSeeder）。
 * 口座（「使わない」口座もふくむ）・小分類・相手と、今月・先月の記録（支出・収入・移動・調整）、定期的な記録を入れる。
 * 本番では動かさない。口座か記録がもう入っているときも、本物のデータとまざらないように入れずに止める
 */
class SampleSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command->error('本番では、ためしのデータは入れません。');

            return;
        }
        if (Account::query()->exists() || Record::query()->exists()) {
            $this->command->error('口座か記録がもう入っているので、ためしのデータは入れません。入れ直すときは php artisan migrate:fresh で空にしてからにしてください（ユーザーも消えるので、user:create で作り直します）。');

            return;
        }

        DB::transaction(function () {
            // 取っておく額（第 2 段階）：銀行口座は金額、Suica は残高より多くして「下回っています」のお知らせが出るようにする
            $bank = Account::create(['name' => 'みずほ銀行', 'type' => AccountType::Bank, 'initial_balance' => 300000,
                'reserve_type' => ReserveType::Amount, 'reserve_amount' => 100000]);
            $cash = Account::create(['name' => '財布', 'type' => AccountType::Cash, 'initial_balance' => 20000]);
            $ic = Account::create(['name' => 'Suica', 'type' => AccountType::TransitIc, 'initial_balance' => 2000,
                'reserve_type' => ReserveType::Amount, 'reserve_amount' => 6000]);
            // 貯金用の口座は「全部取っておく」
            Account::create(['name' => '貯金', 'type' => AccountType::Bank, 'initial_balance' => 150000, 'reserve_type' => ReserveType::All]);
            // 「使わない」にできるのは残高 0 円の口座だけなので、1,200 円の支出でちょうど 0 円になるようにする
            $old = Account::create(['name' => '古い財布', 'type' => AccountType::Cash, 'initial_balance' => 1200]);

            // 手元のデータベースで、最初の分類を消したり名前を変えたりしていることがある。見つからない分類を使う行だけ飛ばして知らせる
            // （全体をエラーで止めると、ほかのためしのデータも入らないため。イシュー #88）
            $food = $this->category('食費');
            $eatOut = $this->child($food, '外食');
            $salary = $this->category('給与', CategoryKind::Income);
            $transport = $this->category('交通費');
            $daily = $this->category('日用品');

            // 日付は動かした日から数える（いつ動かしても、今月と先月の記録になる）。今月の分は今日より先にしない
            $today = CarbonImmutable::today();
            $thisMonth = fn (int $day) => $today->startOfMonth()->addDays($day - 1)->min($today);
            $lastMonth = fn (int $day) => $today->subMonthNoOverflow()->startOfMonth()->addDays($day - 1);

            // 記録を 1 行入れる。分類の false は「分類を使わない行」（移動・調整）。null は「使う分類が見つからなかった行」で、その行は飛ばす
            $record = function (CarbonImmutable $date, RecordType $type, int $amount, Account $account, ?Account $toAccount, Category|false|null $category, ?string $partnerName = null, ?string $item = null): void {
                if ($category === null) {
                    $this->skipped++;

                    return;
                }
                Record::create([
                    'date' => $date,
                    'type' => $type,
                    'amount' => $amount,
                    'account_id' => $account->id,
                    'to_account_id' => $toAccount?->id,
                    'category_id' => $category === false ? null : $category->id,
                    'partner_id' => $partnerName === null ? null : $this->use(Partner::firstOrCreate(['name' => $partnerName]))->id,
                    'item' => $item,
                ]);
            };
            $record($lastMonth(25), RecordType::Income, 250000, $bank, null, $salary, '会社');
            $record($lastMonth(26), RecordType::Transfer, 30000, $bank, $cash, false);
            $record($lastMonth(28), RecordType::Expense, 1200, $old, null, $food, 'パン屋', 'パン');
            $record($thisMonth(1), RecordType::Expense, 3200, $cash, null, $eatOut, 'レストラン');
            $record($thisMonth(5), RecordType::Expense, 210, $ic, null, $transport, '駅');
            $record($thisMonth(8), RecordType::Expense, 2350, $cash, null, $food, 'スーパー');
            $record($thisMonth(8), RecordType::Adjustment, -200, $cash, null, false);
            $record($thisMonth(8), RecordType::Transfer, 3000, $cash, $ic, false);
            $record($thisMonth(9), RecordType::Expense, 680, $cash, null, $food, 'コンビニ', 'おにぎり');
            $record($thisMonth(9), RecordType::Expense, 210, $ic, null, $transport, '駅');
            $record($thisMonth(12), RecordType::Adjustment, 300, $ic, null, false);
            $record($thisMonth(14), RecordType::Expense, 450, $cash, null, $daily, 'ドラッグストア', 'ティッシュ');

            // 定期的な記録（画面設計書 5.10）：金額が変わる収入・決まった金額の支出・選んだ月だけ・移動・終わったもの・「使わない」もの
            // 最初の月は、登録の画面と同じく、記録する日がまだ来ていない月にする（過ぎた月を「今回はなし」にしなくて済むように）
            // 口座はみずほ銀行。$values で、毎月でないもの・金額が変わるもの・移動の「どこへ」・分類・相手・最後の月・「使わない」を変える
            // 分類は $values['category']（Category。見つからなかったときは null で、その定期的な記録は飛ばす。分類を使わないものは書かない）
            $recurring = function (string $name, RecordType $type, int $day, RecurringShift $shift, int $amount, array $values = []) use ($bank, $today) {
                $values += ['months' => null, 'is_fixed' => true, 'to_account_id' => null, 'category' => false, 'partner' => null, 'end_month' => null, 'is_active' => true];
                $end = $values['end_month'];
                $category = $values['category'];
                if ($category === null) {
                    $this->skipped++;

                    return;
                }
                // 動いている予定（「使う」で、終わっていないもの）は、「使わない」分類を使えない（画面設計書 4 章の 9）。手元で「使わない」にしていたら飛ばす
                $running = $values['is_active'] && ($end === null || $end->gte($today->startOfMonth()));
                if ($category instanceof Category && $running && ! $this->usable($category)) {
                    $this->command->warn('分類「'.$category->name.'」が『使わない』なので、定期的な記録「'.$name.'」は入れません。');
                    $this->skipped++;

                    return;
                }
                $created = Recurring::create([
                    'name' => $name,
                    'type' => $type,
                    'day' => $day,
                    'shift' => $shift,
                    'months' => $values['months'],
                    'is_fixed' => $values['is_fixed'],
                    'amount' => $values['is_fixed'] ? $amount : null,
                    'estimated_amount' => $values['is_fixed'] ? null : $amount,
                    'account_id' => $bank->id,
                    'to_account_id' => $values['to_account_id'],
                    'category_id' => $category instanceof Category ? $category->id : null,
                    'partner_id' => $values['partner'] === null ? null : $this->use(Partner::firstOrCreate(['name' => $values['partner']]))->id,
                    // 終わったものは、最後の月の 1 年前から
                    'start_month' => $end !== null ? $end->subYear() : Recurring::firstUpcomingMonth($day, $shift, $values['months'], $today),
                    'end_month' => $end,
                    'is_active' => $values['is_active'],
                ]);
                // 終わったもの（最後の月の 1 年前から）は、記録する日がもう過ぎた月がある。登録の画面と同じく、その月は「今回はなし」にする
                // （アプリを使う前に払った分として扱う。しないと、記録も「今回はなし」もない月が、使える額の予定にたまりつづけるため）
                foreach ($created->monthsToSkipOnSave(null, $today) as $month) {
                    RecurringSkip::create(['recurring_id' => $created->id, 'month' => $month->toDateString()]);
                }
            };
            $recurring('給料', RecordType::Income, 25, RecurringShift::Previous, 250000, ['is_fixed' => false, 'category' => $salary, 'partner' => '会社']);
            $recurring('賞与', RecordType::Income, 10, RecurringShift::Previous, 400000, [
                'months' => [6, 12], 'is_fixed' => false, 'category' => $this->category('賞与', CategoryKind::Income), 'partner' => '会社',
            ]);
            $recurring('家賃', RecordType::Expense, 27, RecurringShift::Next, 70000, ['category' => $this->category('住まい'), 'partner' => '不動産会社']);
            $recurring('Suica へ移す', RecordType::Transfer, 1, RecurringShift::Next, 3000, ['to_account_id' => $ic->id]);
            $recurring('古いローン', RecordType::Expense, 5, RecurringShift::Next, 10000, [
                'category' => $this->category('ローン'), 'partner' => '銀行', 'end_month' => $today->subMonthsNoOverflow(2)->startOfMonth(),
            ]);
            $recurring('ジムの会費', RecordType::Expense, 20, RecurringShift::Next, 8000, ['category' => $this->category('趣味・娯楽'), 'partner' => 'ジム', 'is_active' => false]);

            // 「使わない」にした口座の記録が、元の名前のまま出るかを見るため（記録を入れてから「使わない」にする）。
            // 「使わない」にできるのは残高 0 円の口座だけ（要件定義書 5.4）。0 円にする記録（食費のパン屋）を飛ばしたときは、「使う」のままにする
            if ($old->balance() === 0) {
                $old->update(['is_active' => false]);
            } else {
                $this->command->warn('口座「古い財布」の残高が 0 円にならなかったので、『使わない』にはしていません。');
            }
        });

        // 入れた数は、数えて出す（飛ばした行があると、決まった数にならないため）
        $this->command->info('ためしのデータを入れました（口座 '.Account::query()->count().'・記録 '.Record::query()->count().'・定期的な記録 '.Recurring::query()->count().'）。'
            .($this->skipped > 0 ? '分類が見つからないなどで、'.$this->skipped.' 行は入れていません。' : ''));
    }

    /** 分類が見つからないなどで飛ばした行の数（最後の知らせに出す） */
    private int $skipped = 0;

    /** @var array<string, true> 見つからないと知らせた分類（同じ分類で何度も知らせないため） */
    private array $warned = [];

    /**
     * 同じ名前の相手・小分類がもうあればそれを使う。「使わない」になっていたら「使う」に戻す
     * （記録の登録で「使わない」相手と同じ名前を打ったときと同じ扱い）
     *
     * @template T of Category|Partner
     *
     * @param  T  $model
     * @return T
     */
    private function use(Category|Partner $model): Category|Partner
    {
        if (! $model->wasRecentlyCreated && ! $model->is_active) {
            $model->is_active = true;
            // 「使う」に戻した分類は、同じ段のいちばん下に入れる（データベース設計書 3.3。分類の管理の変更と同じ）
            if ($model instanceof Category) {
                $model->sort_order = (int) Category::query()->sameLevelAs($model)->max('sort_order') + 1;
            }
            $model->save();
        }

        return $model;
    }

    /**
     * 最初からある大分類を名前で探す。見つからないとき（手元で消した・名前を変えた）は、知らせて null を返す。
     * その分類を使う記録・定期的な記録だけを飛ばし、ほかは入れる（全体をエラーで止めないため）
     */
    private function category(string $name, CategoryKind $kind = CategoryKind::Expense): ?Category
    {
        $category = Category::query()->whereNull('parent_id')->where('kind', $kind)->where('name', $name)->first();
        if ($category === null && ! isset($this->warned[$name])) {
            $this->warned[$name] = true;
            $this->command->warn('分類「'.$name.'」が見つからないので、この分類を使う記録・定期的な記録は入れません。');
        }

        return $category;
    }

    /**
     * 大分類の下の小分類（なければ作る）。大分類が見つからない・「使わない」のときは、知らせて null を返す
     * （「使わない」大分類の下に「使う」小分類は置けないため。画面設計書 5.8）
     */
    private function child(?Category $parent, string $name): ?Category
    {
        if ($parent === null) {
            return null;
        }
        if (! $parent->is_active) {
            $this->command->warn('分類「'.$parent->name.'」が『使わない』なので、小分類「'.$name.'」と、それを使う記録は入れません。');

            return null;
        }

        return $this->use(Category::firstOrCreate(['parent_id' => $parent->id, 'name' => $name], ['kind' => $parent->kind]));
    }

    /** 分類が「使う」か（小分類は、大分類も「使う」のときだけ） */
    private function usable(Category $category): bool
    {
        return $category->is_active && ($category->parent_id === null || $category->parent?->is_active === true);
    }
}
