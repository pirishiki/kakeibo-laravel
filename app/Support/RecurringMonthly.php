<?php

namespace App\Support;

use App\Models\Record;
use App\Models\Recurring;
use App\Models\RecurringAmount;
use App\Models\RecurringSkip;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 定期的な記録の、月ごとの金額・今回はなし・もどす（画面設計書 5.10「月ごとの金額」）と、ホームの「金額がまだ」のお知らせ（5.2）
 * 画面は 2 つ（app/Livewire/MonthlyAmounts.php・PendingAmounts.php）だが、出すものの決まりと保存のしかたは、ここ 1 か所にまとめる
 */
class RecurringMonthly
{
    /** 行の状態：記録済み・今回はなし・決まった金額・金額が変わる */
    public const RECORDED = 'recorded';

    public const SKIPPED = 'skipped';

    public const FIXED = 'fixed';

    public const VARIABLE = 'variable';

    /**
     * その月の分の予定の行。記録する日（ずらしたあとの日）の順。
     * 最初の月より前・最後の月より後・選んだ月でない月の予定は出さない。「使わない」の予定は、記録済みか「今回はなし」の月だけ出す
     *
     * @return list<array{recurring: Recurring, month: CarbonImmutable, date: CarbonImmutable, reason: string|null, status: string, amount: int|null, estimate: int|null, recordId: int|null}>
     */
    public static function rows(CarbonImmutable $month): array
    {
        $month = $month->startOfMonth();
        $recurrings = Recurring::query()->withLatestAmount()->get()
            ->filter(fn (Recurring $r) => $r->coversMonth($month));
        if ($recurrings->isEmpty()) {
            return [];
        }

        $ids = $recurrings->modelKeys();
        $key = $month->toDateString();
        $records = Record::query()->whereIn('recurring_id', $ids)->where('recurring_month', $key)->get()->keyBy('recurring_id');
        $skipped = RecurringSkip::query()->whereIn('recurring_id', $ids)->where('month', $key)->pluck('recurring_id')->all();
        $amounts = RecurringAmount::query()->whereIn('recurring_id', $ids)->where('month', $key)->pluck('amount', 'recurring_id');

        $rows = [];
        foreach ($recurrings as $recurring) {
            $record = $records->get($recurring->id);
            $isSkipped = in_array($recurring->id, $skipped, true);
            if (! $recurring->is_active && $record === null && ! $isSkipped) {
                continue;
            }

            $status = match (true) {
                $record !== null => self::RECORDED,
                $isSkipped => self::SKIPPED,
                $recurring->is_fixed => self::FIXED,
                default => self::VARIABLE,
            };
            $date = $recurring->scheduledDate($month);
            $rows[] = [
                'recurring' => $recurring,
                'month' => $month,
                'date' => $date,
                'reason' => self::shiftReason($recurring, $month),
                'status' => $status,
                // 記録済みは記録の金額（記録を直せば、ここも変わる）。金額が変わる予定は、入れてある金額（まだなら null）
                'amount' => match ($status) {
                    self::RECORDED => $record->amount,
                    self::FIXED => $recurring->amount,
                    default => isset($amounts[$recurring->id]) ? (int) $amounts[$recurring->id] : null,
                },
                'estimate' => $recurring->is_fixed ? null : $recurring->estimate(),
                'recordId' => $record?->id,
            ];
        }

        usort($rows, fn (array $a, array $b) => [$a['date'], $a['recurring']->id] <=> [$b['date'], $b['recurring']->id]);

        return $rows;
    }

    /**
     * 「金額がまだ」のもの：金額が変わる「使う」の予定で、お金が動く日（ずらしたあとの日）が今月か、それより前で、
     * 金額も記録も「今回はなし」もない月（画面設計書 5.2）。過ぎた月の分（お金が動く日が先月以前）を上に、そのあとは日付の順
     *
     * @return list<array{recurring: Recurring, month: CarbonImmutable, date: CarbonImmutable, estimate: int|null, label: string|null}>
     */
    public static function pending(?CarbonImmutable $today = null): array
    {
        $today ??= today()->toImmutable();
        $thisMonth = $today->startOfMonth();
        // 来月の分も、前の平日にずらすと今月の日になることがあるので、来月まで見る
        $lastMonth = $thisMonth->addMonth();

        $recurrings = Recurring::query()->where('is_active', true)->where('is_fixed', false)
            ->where('start_month', '<=', $lastMonth->toDateString())
            ->withLatestAmount()
            ->with(['records:id,recurring_id,recurring_month', 'skips', 'amounts'])
            ->get();

        $items = [];
        foreach ($recurrings as $recurring) {
            $done = self::monthKeys($recurring->records->pluck('recurring_month'))
                ->merge(self::monthKeys($recurring->skips->pluck('month')))
                ->merge(self::monthKeys($recurring->amounts->pluck('month')))
                ->all();

            for ($month = $recurring->start_month; $month->lte($lastMonth); $month = $month->addMonth()) {
                $date = $recurring->scheduledDate($month);
                if (! $recurring->coversMonth($month) || $date->gt($thisMonth->endOfMonth())
                    || in_array($month->toDateString(), $done, true)) {
                    continue;
                }
                $items[] = [
                    'recurring' => $recurring,
                    'month' => $month,
                    'date' => $date,
                    'estimate' => $recurring->estimate(),
                    // もとの予定の月が今月でないときは「9月の分」「11月の分」の印をつける
                    'label' => $month->eq($thisMonth) ? null : ($month->year === $today->year ? '' : $month->year.'年').$month->month.'月の分',
                ];
            }
        }

        usort($items, fn (array $a, array $b) => [$a['date']->lt($thisMonth) ? 0 : 1, $a['date'], $a['recurring']->id]
            <=> [$b['date']->lt($thisMonth) ? 0 : 1, $b['date'], $b['recurring']->id]);

        return $items;
    }

    /**
     * 金額が変わる予定の、その月の金額を入れる・直す（null なら消して「金額がまだ」に戻す）。
     * 記録済みの月は直さない（記録の編集で直す）。日付が今日か過ぎていれば、その場で予定の日付で記録する（データベース設計書 3.6）
     *
     * @return bool 記録したか
     */
    public static function saveAmount(Recurring $recurring, CarbonImmutable $month, ?int $amount, ?CarbonImmutable $today = null): bool
    {
        $month = $month->startOfMonth();
        $key = $month->toDateString();
        if ($recurring->is_fixed) {
            return false;
        }

        return DB::transaction(function () use ($recurring, $month, $key, $amount, $today) {
            // 0 時 5 分の自動の記録と重なっても食いちがわないように、予定の行を押さえてから、記録済みかを確かめ直す
            Recurring::query()->whereKey($recurring->id)->lockForUpdate()->first();
            if ($recurring->records()->where('recurring_month', $key)->exists()) {
                return false;
            }
            if ($amount === null) {
                $recurring->amounts()->where('month', $key)->delete();

                return false;
            }

            // 2 つの画面からほぼ同時に保存しても、ユニーク制約のエラーにならないように、あれば書きかえ・なければ作る を 1 文で行う
            RecurringAmount::query()->upsert(
                [['recurring_id' => $recurring->id, 'month' => $key, 'amount' => $amount, 'created_at' => now(), 'updated_at' => now()]],
                ['recurring_id', 'month'],
                ['amount', 'updated_at'],
            );

            return self::recordIfDue($recurring, $month, $today);
        });
    }

    /** その月を「今回はなし」にする。記録済みの月はできない */
    public static function skip(Recurring $recurring, CarbonImmutable $month): void
    {
        $key = $month->startOfMonth()->toDateString();
        DB::transaction(function () use ($recurring, $key) {
            // 自動の記録と重なって「記録もあるのに今回はなし」にならないように、予定の行を押さえてから確かめる
            Recurring::query()->whereKey($recurring->id)->lockForUpdate()->first();
            if ($recurring->records()->where('recurring_month', $key)->exists()) {
                return;
            }

            RecurringSkip::query()->firstOrCreate(['recurring_id' => $recurring->id, 'month' => $key]);
        });
    }

    /**
     * 保存・もどすのあとに、記録できているはずなのに記録されていないときの理由。問題がないときは null
     * （日付が今日か過ぎていて、金額があるのに、その月の記録がない＝口座が「使わない」など。
     *   記録はあるが、入れた金額とちがう＝開いたあとに、前の金額で自動で記録された）
     *
     * @param  int|null  $entered  入れた金額（金額が変わる予定のとき）。決まった金額・もどすのときは null
     */
    public static function recordProblem(Recurring $recurring, CarbonImmutable $month, ?int $entered = null, ?CarbonImmutable $today = null): ?string
    {
        $today ??= today()->toImmutable();
        $month = $month->startOfMonth();
        if ($recurring->scheduledDate($month)->gt($today) || $recurring->skips()->where('month', $month->toDateString())->exists()) {
            return null;
        }

        $record = $recurring->records()->where('recurring_month', $month->toDateString())->first();
        if ($record !== null) {
            return $entered !== null && $record->amount !== $entered
                ? '「'.$recurring->name.'」は、開いたあとに前の金額（'.number_format($record->amount).' 円）で記録されていたので、入れた金額は使っていません。記録済みの金額を押して記録を直してください。'
                : null;
        }

        if (RecurringRecorder::amountFor($recurring, $month) === null) {
            return null;
        }

        return RecurringRecorder::blocker($recurring) ?? '「'.$recurring->name.'」を記録できませんでした。画面を読みこみ直してから、もう一度ためしてください。';
    }

    /**
     * 「今回はなし」をやめて元に戻す。日付が今日か過ぎていて金額があれば、すぐに予定の日付で記録する（「使わない」の予定でも。データベース設計書 3.6）
     *
     * @return bool 記録したか
     */
    public static function restore(Recurring $recurring, CarbonImmutable $month, ?CarbonImmutable $today = null): bool
    {
        $month = $month->startOfMonth();

        return DB::transaction(function () use ($recurring, $month, $today) {
            $recurring->skips()->where('month', $month->toDateString())->delete();

            return self::recordIfDue($recurring, $month, $today);
        });
    }

    /**
     * ずらした理由：「25日が日曜のため前の平日」。ずらしていないときは null
     */
    public static function shiftReason(Recurring $recurring, CarbonImmutable $month): ?string
    {
        $first = $month->startOfMonth();
        $planned = $first->setDay(min($recurring->day, $first->daysInMonth));
        if ($recurring->scheduledDate($month)->eq($planned)) {
            return null;
        }

        $why = match (true) {
            $planned->isSaturday() => '土曜',
            $planned->isSunday() => '日曜',
            ($planned->month === 12 && $planned->day === 31) || ($planned->month === 1 && $planned->day <= 3) => '年末年始の休み',
            default => '祝日',
        };

        return $planned->day.'日が'.$why.'のため'.$recurring->shift->label();
    }

    /** 日付が今日か過ぎていれば記録する */
    private static function recordIfDue(Recurring $recurring, CarbonImmutable $month, ?CarbonImmutable $today): bool
    {
        $today ??= today()->toImmutable();

        return $recurring->scheduledDate($month)->lte($today) && RecurringRecorder::record($recurring, $month);
    }

    /**
     * @param  Collection<int, mixed>  $months
     * @return Collection<int, string>
     */
    private static function monthKeys(Collection $months): Collection
    {
        return $months->filter()->map(fn ($m) => CarbonImmutable::parse($m)->toDateString())->values();
    }
}
