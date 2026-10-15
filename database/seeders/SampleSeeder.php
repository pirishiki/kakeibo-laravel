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

            $food = $this->category('食費');
            $eatOut = $this->use(Category::firstOrCreate(['parent_id' => $food->id, 'name' => '外食'], ['kind' => CategoryKind::Expense]));
            $salary = $this->category('給与', CategoryKind::Income);
            $transport = $this->category('交通費');
            $daily = $this->category('日用品');

            // 日付は動かした日から数える（いつ動かしても、今月と先月の記録になる）。今月の分は今日より先にしない
            $today = CarbonImmutable::today();
            $thisMonth = fn (int $day) => $today->startOfMonth()->addDays($day - 1)->min($today);
            $lastMonth = fn (int $day) => $today->subMonthNoOverflow()->startOfMonth()->addDays($day - 1);

            $rows = [
                [$lastMonth(25), RecordType::Income, 250000, $bank, null, $salary, '会社', null],
                [$lastMonth(26), RecordType::Transfer, 30000, $bank, $cash, null, null, null],
                [$lastMonth(28), RecordType::Expense, 1200, $old, null, $food, 'パン屋', 'パン'],
                [$thisMonth(1), RecordType::Expense, 3200, $cash, null, $eatOut, 'レストラン', null],
                [$thisMonth(5), RecordType::Expense, 210, $ic, null, $transport, '駅', null],
                [$thisMonth(8), RecordType::Expense, 2350, $cash, null, $food, 'スーパー', null],
                [$thisMonth(8), RecordType::Adjustment, -200, $cash, null, null, null, null],
                [$thisMonth(8), RecordType::Transfer, 3000, $cash, $ic, null, null, null],
                [$thisMonth(9), RecordType::Expense, 680, $cash, null, $food, 'コンビニ', 'おにぎり'],
                [$thisMonth(9), RecordType::Expense, 210, $ic, null, $transport, '駅', null],
                [$thisMonth(12), RecordType::Adjustment, 300, $ic, null, null, null, null],
                [$thisMonth(14), RecordType::Expense, 450, $cash, null, $daily, 'ドラッグストア', 'ティッシュ'],
            ];
            foreach ($rows as [$date, $type, $amount, $account, $toAccount, $category, $partnerName, $item]) {
                Record::create([
                    'date' => $date,
                    'type' => $type,
                    'amount' => $amount,
                    'account_id' => $account->id,
                    'to_account_id' => $toAccount?->id,
                    'category_id' => $category?->id,
                    'partner_id' => $partnerName === null ? null : $this->use(Partner::firstOrCreate(['name' => $partnerName]))->id,
                    'item' => $item,
                ]);
            }

            // 定期的な記録（画面設計書 5.10）：金額が変わる収入・決まった金額の支出・選んだ月だけ・移動・終わったもの・「使わない」もの
            // 最初の月は、登録の画面と同じく、記録する日がまだ来ていない月にする（過ぎた月を「今回はなし」にしなくて済むように）
            // 口座はみずほ銀行。$values で、毎月でないもの・金額が変わるもの・移動の「どこへ」・分類・相手・最後の月・「使わない」を変える
            $recurring = function (string $name, RecordType $type, int $day, RecurringShift $shift, int $amount, array $values = []) use ($bank, $today) {
                $values += ['months' => null, 'is_fixed' => true, 'to_account_id' => null, 'category_id' => null, 'partner' => null, 'end_month' => null, 'is_active' => true];
                $end = $values['end_month'];
                Recurring::create([
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
                    'category_id' => $values['category_id'],
                    'partner_id' => $values['partner'] === null ? null : $this->use(Partner::firstOrCreate(['name' => $values['partner']]))->id,
                    // 終わったものは、最後の月の 1 年前から
                    'start_month' => $end !== null ? $end->subYear() : Recurring::firstUpcomingMonth($day, $shift, $values['months'], $today),
                    'end_month' => $end,
                    'is_active' => $values['is_active'],
                ]);
            };
            $recurring('給料', RecordType::Income, 25, RecurringShift::Previous, 250000, ['is_fixed' => false, 'category_id' => $salary->id, 'partner' => '会社']);
            $recurring('賞与', RecordType::Income, 10, RecurringShift::Previous, 400000, [
                'months' => [6, 12], 'is_fixed' => false, 'category_id' => $this->category('賞与', CategoryKind::Income)->id, 'partner' => '会社',
            ]);
            $recurring('家賃', RecordType::Expense, 27, RecurringShift::Next, 70000, ['category_id' => $this->category('住まい')->id, 'partner' => '不動産会社']);
            $recurring('Suica へ移す', RecordType::Transfer, 1, RecurringShift::Next, 3000, ['to_account_id' => $ic->id]);
            $recurring('古いローン', RecordType::Expense, 5, RecurringShift::Next, 10000, [
                'category_id' => $this->category('ローン')->id, 'partner' => '銀行', 'end_month' => $today->subMonthsNoOverflow(2)->startOfMonth(),
            ]);
            $recurring('ジムの会費', RecordType::Expense, 20, RecurringShift::Next, 8000, ['category_id' => $this->category('趣味・娯楽')->id, 'partner' => 'ジム', 'is_active' => false]);

            // 「使わない」にした口座の記録が、元の名前のまま出るかを見るため（記録を入れてから「使わない」にする）
            $old->update(['is_active' => false]);
        });

        $this->command->info('ためしのデータを入れました（口座 4・記録 12・定期的な記録 6）。');
    }

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

    private function category(string $name, CategoryKind $kind = CategoryKind::Expense): Category
    {
        return Category::query()->whereNull('parent_id')->where('kind', $kind)->where('name', $name)->firstOrFail();
    }
}
