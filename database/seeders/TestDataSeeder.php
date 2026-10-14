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
 * デプロイした環境に入れるテスト用のデータ（php artisan db:seed --class=TestDataSeeder --force）。
 * 口座 5・分類 20・相手 50・記録 1 万件（今日までの 5 年分）。1 万件でも 1 秒以内に開くかを確かめるのにも使う（要件定義書 10.4、N-12）。
 * 本番の EC2 には開発用の部品（Faker）を入れないので、決まった並びでデータを組み立てる（全体構成設計書 6.2）。
 * 何度動かしても同じデータになる（日付だけは、動かした日から数える）。口座か記録がもう入っているときは入れずに止める
 */
class TestDataSeeder extends Seeder
{
    /** 記録の件数 */
    public const RECORDS = 10000;

    /** 何か月分か（5 年） */
    private const MONTHS = 60;

    /** 相手の名前：お店の種類 × 場所。最初からある「その他」と合わせて 50 にする（服屋だけ 4 つ） */
    private const SHOPS = ['コンビニ', 'スーパー', 'ドラッグストア', 'カフェ', 'パン屋', '定食屋', '本屋', '100円ショップ', 'ホームセンター', '服屋'];

    private const PLACES = ['駅前', '本町', '川沿い', '北口', '南口'];

    public function run(): void
    {
        if (Account::query()->exists() || Record::query()->exists()) {
            $this->command->error('口座か記録がもう入っているので、テスト用のデータは入れません。入れ直すときは php artisan migrate:fresh --force で空にしてからにしてください（ユーザーも消えるので、user:create で作り直します）。');

            return;
        }

        // 決まった種（たね）から数を出すので、何度動かしても同じ並びになる（でたらめに見えるが、毎回同じ）
        mt_srand(20261015);

        DB::transaction(function () {
            $today = CarbonImmutable::today();
            $first = $today->startOfMonth()->subMonthsNoOverflow(self::MONTHS - 1);

            $bank = Account::create(['name' => 'みどり銀行', 'type' => AccountType::Bank, 'initial_balance' => 500000]);
            $savings = Account::create(['name' => 'あおば銀行（貯金）', 'type' => AccountType::Bank, 'initial_balance' => 1000000]);
            $cash = Account::create(['name' => '財布', 'type' => AccountType::Cash, 'initial_balance' => 20000]);
            $ic = Account::create(['name' => 'Suica', 'type' => AccountType::TransitIc, 'initial_balance' => 3000]);
            $card = Account::create(['name' => 'クレジットカード', 'type' => AccountType::CreditCard, 'initial_balance' => 0]);

            // 分類は最初からある 19 に、小分類「食費 > 外食」を足して 20
            $food = $this->category('食費');
            $eatOut = Category::create(['parent_id' => $food->id, 'name' => '外食', 'kind' => CategoryKind::Expense]);
            $daily = $this->category('日用品');
            $transport = $this->category('交通費');
            $housing = $this->category('住まい');
            $utility = $this->category('水道・光熱費');
            $phone = $this->category('通信費');
            $hobby = $this->category('趣味・娯楽');
            $clothes = $this->category('衣服・美容');
            $salary = $this->category('給与', CategoryKind::Income);
            $bonus = $this->category('賞与', CategoryKind::Income);

            $partners = [];
            foreach (self::SHOPS as $shop) {
                foreach (self::PLACES as $place) {
                    if ($shop === '服屋' && $place === '南口') {
                        continue;
                    }
                    $partners[$shop][] = Partner::create(['name' => $shop.' '.$place])->id;
                }
            }

            // 何か月目か（最初の月が 0）。カードの引き落としで「前の月に使った分」を数えるのに使う
            $monthIndex = fn (CarbonImmutable $date) => ($date->year - $first->year) * 12 + $date->month - $first->month;

            // 毎月決まった記録：給料・賞与（6 月と 12 月）・家賃・電気代・携帯代・現金をおろす・貯金・カードの引き落とし（前の月に使った分）。
            // 今月の分は、今日より先の日のものは入れない（テスト用のデータに、先の日付の記録は入れない）
            $fixed = [];
            for ($m = 0; $m < self::MONTHS; $m++) {
                $month = $first->addMonthsNoOverflow($m);
                foreach ([
                    [25, RecordType::Income, 250000, $bank, null, $salary],
                    [10, RecordType::Income, in_array($month->month, [6, 12], true) ? 300000 : 0, $bank, null, $bonus],
                    [27, RecordType::Expense, 72000, $bank, null, $housing],
                    [20, RecordType::Expense, intdiv(mt_rand(6000, 14000), 10) * 10, $bank, null, $utility],
                    [15, RecordType::Expense, 4800, $bank, null, $phone],
                    [1, RecordType::Transfer, 50000, $bank, $cash, null],
                    [26, RecordType::Transfer, 10000, $bank, $savings, null],
                    // 金額は、ふだんの記録を作ったあとで決める（下）
                    [10, RecordType::Transfer, 0, $bank, $card, null],
                ] as [$d, $type, $amount, $account, $toAccount, $category]) {
                    $date = $month->setDay($d);
                    // 賞与のない月・まだ来ていない日・最初の月のカードの引き落とし（前の月がない）は入れない
                    if (($category?->is($bonus) && $amount === 0) || $date->greaterThan($today) || ($m === 0 && $toAccount?->is($card))) {
                        continue;
                    }
                    $fixed[] = [$date, $type, $amount, $account, $toAccount, $category, null];
                }
            }

            // ふだんの記録：何にいくらくらい、どの口座で（お店の種類・分類・口座・金額の下と上・出てくる多さ）。
            // 1 か月の出入りが、給料と賞与でまかなえるくらいにしてある（5 年たっても、残高が大きなマイナスにならない）
            $usual = [
                ['コンビニ', $food, $cash, 150, 800, 6],
                ['スーパー', $food, $card, 500, 3000, 4],
                ['パン屋', $food, $cash, 200, 700, 2],
                ['定食屋', $eatOut, $cash, 700, 1200, 2],
                ['カフェ', $eatOut, $ic, 300, 700, 2],
                ['ドラッグストア', $daily, $card, 300, 2000, 2],
                ['100円ショップ', $daily, $cash, 110, 660, 1],
                ['ホームセンター', $daily, $card, 500, 3000, 1],
                ['本屋', $hobby, $card, 600, 2000, 1],
                ['服屋', $clothes, $card, 2000, 6000, 1],
            ];
            // 多さの数だけ並べた表から選ぶ（コンビニは 6 回分あるので、服屋の 6 倍出てくる）
            $picks = [];
            foreach ($usual as $index => $kind) {
                array_push($picks, ...array_fill(0, $kind[5], $index));
            }

            // 残りの件数を、ふだんの記録として 5 年に散らす。Suica のチャージ（現金 → Suica）は 50 件に 1 回、Suica で払う電車代は 20 件に 1 回
            $rows = [];
            $cardTotals = [];
            $count = self::RECORDS - count($fixed);
            $days = (int) $first->diffInDays($today) + 1;
            for ($i = 0; $i < $count; $i++) {
                $date = $first->addDays(intdiv($i * $days, $count));
                if ($i % 50 === 0) {
                    $rows[] = [$date, RecordType::Transfer, 3000, $cash, $ic, null, null];

                    continue;
                }
                if ($i % 20 === 1) {
                    $rows[] = [$date, RecordType::Expense, 178 * mt_rand(1, 3), $ic, null, $transport, null];

                    continue;
                }
                [$shop, $category, $account, $min, $max] = $usual[$picks[mt_rand(0, count($picks) - 1)]];
                // 10 円単位にそろえる
                $amount = intdiv(mt_rand($min, $max), 10) * 10;
                $rows[] = [$date, RecordType::Expense, $amount, $account, null, $category, $partners[$shop][mt_rand(0, count($partners[$shop]) - 1)]];
                if ($account->is($card)) {
                    $cardTotals[$monthIndex($date)] = ($cardTotals[$monthIndex($date)] ?? 0) + $amount;
                }
            }

            // カードの引き落とし：前の月にカードで使った分を、銀行口座からカードへ移す
            foreach ($fixed as $i => $row) {
                if ($row[4]?->is($card)) {
                    $fixed[$i][2] = max(1, $cardTotals[$monthIndex($row[0]) - 1] ?? 0);
                }
            }

            // 1,000 件ずつまとめて入れる（1 件ずつより、ずっと速い）
            $all = collect([...$fixed, ...$rows])->sortBy(fn ($row) => $row[0]->toDateString())->values();
            foreach ($all->chunk(1000) as $chunk) {
                Record::query()->insert($chunk->map(fn ($row) => [
                    'date' => $row[0]->toDateString(),
                    'type' => $row[1]->value,
                    'amount' => $row[2],
                    'account_id' => $row[3]->id,
                    'to_account_id' => $row[4]?->id,
                    'category_id' => $row[5]?->id,
                    'partner_id' => $row[6],
                    // 入力した日時は、その日の夜にしておく（ホームの最近の記録が、日付の新しいものから並ぶように）
                    'created_at' => $row[0]->setTime(21, 0),
                    'updated_at' => $row[0]->setTime(21, 0),
                ])->all());
            }
        });

        $this->command->info('テスト用のデータを入れました（口座 5・分類 20・相手 50・記録 '.number_format(Record::query()->count()).' 件）。');
    }

    private function category(string $name, CategoryKind $kind = CategoryKind::Expense): Category
    {
        return Category::query()->whereNull('parent_id')->where('kind', $kind)->where('name', $name)->firstOrFail();
    }
}
