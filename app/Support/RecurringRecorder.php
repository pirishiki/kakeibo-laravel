<?php

namespace App\Support;

use App\Models\Account;
use App\Models\Record;
use App\Models\Recurring;
use App\Models\RecurringAmount;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 定期的な記録を、records に記録する（データベース設計書 3.6「自動で記録する仕組み」）
 * 毎日 0 時 5 分のコマンド（recurrings:record）と、保存したその場で記録するとき（画面の側）の、両方から使う。
 * 同じ予定の同じ月の分は 1 回だけ。2 回目はデータベースのユニーク制約が止め、ここでは「もう記録済み」として扱う（N-10、全体構成設計書 5 章）
 */
class RecurringRecorder
{
    /**
     * 日付が来た分のうち、まだ記録していないものを、すべての予定について記録する（動かなかった日の分もまとめて）
     *
     * @return int 記録した件数
     */
    public static function recordAllDue(?CarbonImmutable $today = null): int
    {
        $count = 0;
        Recurring::query()->where('is_active', true)->get()
            ->each(function (Recurring $recurring) use ($today, &$count) {
                // 1 つの予定でまちがいが起きても、ログに残して、ほかの予定は記録する（全体構成設計書 5 章）
                try {
                    // 口座が「使わない」で記録できない予定は、ログに残す（記録されないまま、お金の動きがわからなくならないように）
                    $blocker = self::blocker($recurring);
                    if ($blocker !== null && self::dueMonths($recurring, $today) !== []) {
                        Log::warning($blocker);

                        return;
                    }
                    $count += self::recordDue($recurring, $today);
                } catch (Throwable $e) {
                    report($e);
                }
            });

        return $count;
    }

    /**
     * 1 つの予定の、日付が来ていて、まだ記録していない月を記録する
     *
     * @return int 記録した件数
     */
    public static function recordDue(Recurring $recurring, ?CarbonImmutable $today = null): int
    {
        $count = 0;
        foreach (self::dueMonths($recurring, $today) as $month) {
            if (self::record($recurring, $month)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * 記録する月：記録する日（ずらしたあとの日）が今日か過ぎていて、金額があり、まだ記録も「今回はなし」もしていない月。
     * 記録しないのは、最初の月より前・最後の月より後・「使わない」・選んだ月でない月・「今回はなし」・金額がまだの月（データベース設計書 3.6）
     *
     * @return list<CarbonImmutable> 月（その月の 1 日）の並び。古い順
     */
    public static function dueMonths(Recurring $recurring, ?CarbonImmutable $today = null): array
    {
        if (! $recurring->is_active) {
            return [];
        }

        $today ??= today()->toImmutable();
        // 来月の分も、前の平日にずらすと今月の日になることがある（例：11月1日（日）→ 10月30日（金））ので、来月まで見る
        $last = $today->startOfMonth()->addMonth();
        if ($recurring->end_month !== null && $recurring->end_month->lt($last)) {
            $last = $recurring->end_month;
        }

        $done = [
            ...$recurring->records()->whereNotNull('recurring_month')->pluck('recurring_month')->all(),
            ...$recurring->skips()->pluck('month')->all(),
        ];
        $done = array_map(fn ($m) => CarbonImmutable::parse($m)->toDateString(), $done);
        $amounts = $recurring->is_fixed ? [] : self::enteredAmounts($recurring);

        $months = [];
        for ($month = $recurring->start_month; $month->lte($last); $month = $month->addMonth()) {
            $key = $month->toDateString();
            if (! $recurring->coversMonth($month) || in_array($key, $done, true)
                || $recurring->scheduledDate($month)->gt($today)) {
                continue;
            }
            if (! $recurring->is_fixed && ! isset($amounts[$key])) {
                continue;
            }
            $months[] = $month;
        }

        return $months;
    }

    /**
     * その月の金額。決まった金額の予定は決まった金額、金額が変わる予定は、その月に入れた金額（まだなら null）
     */
    public static function amountFor(Recurring $recurring, CarbonImmutable $month): ?int
    {
        if ($recurring->is_fixed) {
            return $recurring->amount;
        }

        $amount = $recurring->amounts()->where('month', $month->startOfMonth()->toDateString())->value('amount');

        return $amount === null ? null : (int) $amount;
    }

    /**
     * 記録できない理由：口座（移動は「どこから」「どこへ」）が「使わない」のとき。記録できるときは null。
     * 「使わない」口座の残高はいつも 0 円、という決まりを守るため（データベース設計書 3.2）
     */
    public static function blocker(Recurring $recurring): ?string
    {
        $inactive = Account::query()->whereIn('id', array_filter([$recurring->account_id, $recurring->to_account_id]))
            ->where('is_active', false)->value('name');

        return $inactive === null ? null : '口座「'.$inactive.'」が『使わない』なので、「'.$recurring->name.'」を記録できません。先に口座を『使う』に戻してください。';
    }

    /**
     * その月の分を、予定の日付で 1 件記録する。「使う」かどうか・日付が来たかは見ない（「もどす」は「使わない」の予定でも記録するため）
     * 記録しないのは、金額がまだのとき・もう記録済みか「今回はなし」のとき・口座が「使わない」のとき（blocker）
     *
     * 0 時 5 分の自動の記録と、画面での金額の保存・今回はなしが同時に動いても食いちがわないように、
     * その予定の行を押さえて（lockForUpdate）から、記録済みか・今回はなしか・金額を読み直す
     *
     * @return bool 記録したか
     */
    public static function record(Recurring $recurring, CarbonImmutable $month): bool
    {
        $month = $month->startOfMonth();
        if (self::blocker($recurring) !== null) {
            return false;
        }

        return DB::transaction(function () use ($recurring, $month) {
            Recurring::query()->whereKey($recurring->id)->lockForUpdate()->first();
            $key = $month->toDateString();
            if ($recurring->records()->where('recurring_month', $key)->exists() || $recurring->skips()->where('month', $key)->exists()) {
                return false;
            }

            return self::insert($recurring, $month);
        });
    }

    /** 記録の行を 1 つ作る（record から、予定の行を押さえたあとに呼ぶ） */
    private static function insert(Recurring $recurring, CarbonImmutable $month): bool
    {
        $amount = self::amountFor($recurring, $month);
        if ($amount === null) {
            return false;
        }

        $record = new Record([
            'date' => $recurring->scheduledDate($month)->toDateString(),
            'type' => $recurring->type,
            'amount' => $amount,
            'account_id' => $recurring->account_id,
            'to_account_id' => $recurring->to_account_id,
            'category_id' => $recurring->category_id,
            'partner_id' => $recurring->partner_id,
        ]);
        // どの予定の何月の分か。画面からは入れない列なので、fill ではなく直接入れる
        $record->recurring_id = $recurring->id;
        $record->recurring_month = $month;

        try {
            $record->save();
        } catch (UniqueConstraintViolationException) {
            // 同じ予定の同じ月の分が、もう記録してある（0 時 5 分と、その場で記録が重なったときなど）
            return false;
        }

        return true;
    }

    /**
     * 金額が変わる予定の、入れてある月ごとの金額
     *
     * @return array<string, int> 月（Y-m-d）→ 金額
     */
    private static function enteredAmounts(Recurring $recurring): array
    {
        return RecurringAmount::query()->where('recurring_id', $recurring->id)->get()
            ->mapWithKeys(fn (RecurringAmount $row) => [$row->month->toDateString() => $row->amount])
            ->all();
    }
}
