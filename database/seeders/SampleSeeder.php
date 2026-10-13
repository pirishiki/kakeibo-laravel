<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Enums\CategoryKind;
use App\Enums\RecordType;
use App\Models\Account;
use App\Models\Category;
use App\Models\Partner;
use App\Models\Record;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 手元で画面を確かめるための、ためしのデータ（php artisan db:seed --class=SampleSeeder）。
 * 口座（「使わない」口座もふくむ）・小分類・相手と、今月・先月の記録（支出・収入・移動・調整）を入れる。
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
            $bank = Account::create(['name' => 'みずほ銀行', 'type' => AccountType::Bank, 'initial_balance' => 300000]);
            $cash = Account::create(['name' => '財布', 'type' => AccountType::Cash, 'initial_balance' => 20000]);
            $ic = Account::create(['name' => 'Suica', 'type' => AccountType::TransitIc, 'initial_balance' => 2000]);
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

            // 「使わない」にした口座の記録が、元の名前のまま出るかを見るため（記録を入れてから「使わない」にする）
            $old->update(['is_active' => false]);
        });

        $this->command->info('ためしのデータを入れました（口座 4・記録 12）。');
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
