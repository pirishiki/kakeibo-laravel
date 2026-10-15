<?php

namespace App\Support;

use App\Enums\AccountType;
use App\Enums\RecordType;
use App\Enums\ReserveType;
use App\Models\Account;
use App\Models\Record;
use App\Models\Recurring;
use Carbon\CarbonImmutable;

/**
 * 使える額と、ホームのカレンダーの予定（要件定義書 5.5、画面設計書 5.2「計算の決まり」「カレンダー」）
 *
 * 使える額 ＝ 今の合計残高 − 取っておく額の合計 − 今月末までの予定の支出
 * 予定は 2 種類：まだ記録されていない定期的な記録（日付が過ぎて金額がまだのもの・過ぎた月の分もふくむ）と、手で入れた明日から今月末までの記録。
 * 収入は足さない。移動は合計を変えないので引かないが、口座ごとの見込みの残高（取っておく額を数えるもの）は動かす
 */
class AvailableAmount
{
    /**
     * @return array{
     *   total: int, reserved: int, planned: int, available: int, daysLeft: int, perDay: int, perWeek: int,
     *   plans: list<array{name: string, date: CarbonImmutable, amount: int, estimated: bool, recurringId: int|null, recordId: int|null}>,
     *   reserves: array<int, array{counted: int, projected: int, short: bool}>,
     * }
     */
    public static function calculate(?CarbonImmutable $today = null): array
    {
        $today ??= today()->toImmutable();
        $accounts = Account::query()->where('is_active', true)->ordered()->get();
        $balances = Account::balances($today, $accounts->modelKeys());

        // 口座ごとの見込みの残高：今の残高から、予定の支出・移動を動かす（収入は足さない）
        $projected = $balances;
        $plans = [];
        foreach (self::items($today) as $item) {
            $type = $item['type'];
            // 収入は足さない（見込みの残高も動かさない）
            if ($type === RecordType::Income) {
                continue;
            }
            $projected[$item['accountId']] = ($projected[$item['accountId']] ?? 0) - $item['amount'];
            if ($type === RecordType::Transfer) {
                if ($item['toAccountId'] !== null) {
                    $projected[$item['toAccountId']] = ($projected[$item['toAccountId']] ?? 0) + $item['amount'];
                }
            } else {
                // 支出（とマイナスの調整）だけを、使える額から予定として引く
                $plans[] = [
                    'name' => $item['name'],
                    'date' => $item['date'],
                    'amount' => $item['amount'],
                    'estimated' => $item['estimated'],
                    'recurringId' => $item['recurringId'],
                    'recordId' => $item['recordId'],
                ];
            }
        }

        $reserves = [];
        foreach ($accounts as $account) {
            if ($account->reserve_type === ReserveType::None || $account->type === AccountType::CreditCard) {
                continue;
            }
            $left = max(0, $projected[$account->id] ?? 0);
            $target = $account->reserve_type === ReserveType::Amount ? (int) $account->reserve_amount : null;
            $reserves[$account->id] = [
                // 口座の見込みの残高の分までしか取っておけない。マイナスなら 0 円
                'counted' => $target === null ? $left : min($target, $left),
                'projected' => $projected[$account->id] ?? 0,
                // 「全部取っておく」は、いつも残高と同じ額なので、下回ることはない
                'short' => $target !== null && ($projected[$account->id] ?? 0) < $target,
            ];
        }

        usort($plans, fn (array $a, array $b) => [$a['date'], $a['recurringId'] ?? 0, $a['recordId'] ?? 0]
            <=> [$b['date'], $b['recurringId'] ?? 0, $b['recordId'] ?? 0]);

        $total = array_sum($balances);
        $reserved = array_sum(array_column($reserves, 'counted'));
        $planned = array_sum(array_column($plans, 'amount'));
        $available = $total - $reserved - $planned;
        $daysLeft = (int) $today->diffInDays($today->endOfMonth()->startOfDay()) + 1;
        // intdiv は 0 に近いほうへ切り捨てる（-11,531 ÷ 7 → -1,647）
        $perDay = intdiv($available, $daysLeft);

        return [
            'total' => $total,
            'reserved' => $reserved,
            'planned' => $planned,
            'available' => $available,
            'daysLeft' => $daysLeft,
            'perDay' => $perDay,
            'perWeek' => $perDay * min(7, $daysLeft),
            'plans' => $plans,
            'reserves' => $reserves,
        ];
    }

    /**
     * ホームのカレンダーの灰色の予定：まだ記録されていない定期的な記録を、お金が動く日（ずらしたあとの日）のマスに出す。
     * その月の日に動くものだけ（もとの予定の月がとなりの月でも、お金が動く日の月で数える）。移動は出さない
     *
     * @return array<string, array{expense: int, income: int}> 日（2026-10-09 の形）→ 支出・収入の予定の合計
     */
    public static function calendarPlans(CarbonImmutable $month, ?CarbonImmutable $today = null): array
    {
        $month = $month->startOfMonth();
        $days = [];
        foreach (self::recurringItems($month->subMonth(), $month->addMonth()) as $item) {
            if (! $item['date']->isSameMonth($month) || $item['type'] === RecordType::Transfer) {
                continue;
            }
            $key = $item['date']->toDateString();
            $days[$key] ??= ['expense' => 0, 'income' => 0];
            $days[$key][$item['type'] === RecordType::Income ? 'income' : 'expense'] += $item['amount'];
        }

        return $days;
    }

    /**
     * 今月末までの予定（定期的な記録のまだ記録されていない分と、手で入れた明日から今月末までの記録）
     *
     * @return list<array{name: string, type: RecordType, date: CarbonImmutable, amount: int, estimated: bool, accountId: int, toAccountId: int|null, recurringId: int|null, recordId: int|null}>
     */
    private static function items(CarbonImmutable $today): array
    {
        $endOfMonth = $today->endOfMonth()->startOfDay();
        $items = [];

        // 過ぎた月の分も、金額を入れるか「今回はなし」にするまで引く。お金が動く日が来月になるものは入れない
        foreach (self::recurringItems(null, $today->startOfMonth()->addMonth()) as $item) {
            if ($item['date']->lte($endOfMonth)) {
                $items[] = $item;
            }
        }

        $records = Record::query()
            ->with('partner')
            ->whereBetween('date', [$today->addDay()->toDateString(), $endOfMonth->toDateString()])
            ->get();
        foreach ($records as $record) {
            // 調整は、マイナス（へらす）だけを支出と同じに数える。プラスは収入と同じく足さない
            if ($record->type === RecordType::Income || ($record->type === RecordType::Adjustment && $record->amount >= 0)) {
                continue;
            }
            $items[] = [
                'name' => $record->partner->name ?? ($record->type === RecordType::Transfer ? '移動' : '調整'),
                'type' => $record->type,
                'date' => $record->date,
                'amount' => abs($record->amount),
                'estimated' => false,
                'accountId' => $record->account_id,
                'toAccountId' => $record->to_account_id,
                'recurringId' => null,
                'recordId' => $record->id,
            ];
        }

        return $items;
    }

    /**
     * 「使う」の定期的な記録の、まだ記録も「今回はなし」もしていない月の分（$from〜$until の月。$from が null なら最初の月から）。
     * 金額がまだのものは概算（概算もないときは 0 円）
     *
     * @return list<array{name: string, type: RecordType, date: CarbonImmutable, amount: int, estimated: bool, accountId: int, toAccountId: int|null, recurringId: int|null, recordId: int|null}>
     */
    private static function recurringItems(?CarbonImmutable $from, CarbonImmutable $until): array
    {
        $recurrings = Recurring::query()->where('is_active', true)
            ->where('start_month', '<=', $until->toDateString())
            ->withLatestAmount()
            ->with(['records:id,recurring_id,recurring_month', 'skips', 'amounts'])
            ->get();

        $items = [];
        foreach ($recurrings as $recurring) {
            $done = $recurring->records->pluck('recurring_month')->merge($recurring->skips->pluck('month'))
                ->filter()->map(fn ($m) => CarbonImmutable::parse($m)->toDateString())->all();
            $amounts = $recurring->amounts->mapWithKeys(fn ($a) => [CarbonImmutable::parse($a->month)->toDateString() => (int) $a->amount]);

            $month = $from === null || $from->lt($recurring->start_month) ? $recurring->start_month : $from->startOfMonth();
            for (; $month->lte($until); $month = $month->addMonth()) {
                $key = $month->toDateString();
                if (! $recurring->coversMonth($month) || in_array($key, $done, true)) {
                    continue;
                }
                $amount = $recurring->is_fixed ? $recurring->amount : ($amounts[$key] ?? null);
                $items[] = [
                    'name' => $recurring->name,
                    'type' => $recurring->type,
                    'date' => $recurring->scheduledDate($month),
                    'amount' => $amount ?? ($recurring->estimate() ?? 0),
                    'estimated' => $amount === null,
                    'accountId' => $recurring->account_id,
                    'toAccountId' => $recurring->to_account_id,
                    'recurringId' => $recurring->id,
                    'recordId' => null,
                ];
            }
        }

        return $items;
    }
}
