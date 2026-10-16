<?php

namespace App\Support;

use App\Models\Account;
use App\Models\Goal;
use Carbon\CarbonImmutable;

/**
 * 貯金の目標の進み具合（画面設計書 5.13）。一覧とホームで同じ計算を使う。
 * 貯めた額 ＝ 選んだ口座の今の残高（今日までの記録で計算。ホームの今の残高と同じ）
 */
class Goals
{
    /**
     * 目標を一覧の順（期限の近い順、期限なしはその下に追加した順）に、進み具合をつけて返す
     *
     * @return list<array{goal: Goal, balance: int, percent: int, achieved: bool, overdue: bool, monthsLeft: int|null, perMonth: int|null}>
     */
    public static function list(CarbonImmutable $today): array
    {
        $goals = Goal::query()->with('account')->ordered()->get();
        $balances = $goals->isEmpty() ? [] : Account::balances($today, $goals->pluck('account_id')->all());

        return $goals
            ->map(fn (Goal $goal) => self::progress($goal, $balances[$goal->account_id] ?? 0, $today))
            ->values()
            ->all();
    }

    /**
     * 1 つの目標の進み具合
     *
     * - percent：残高 ÷ 目標額の %。1 % 未満は切り捨て（299,000 ÷ 300,000 → 99%。100% は達成したときだけ）。残高がマイナスなら 0
     * - achieved：残高が目標額以上
     * - monthsLeft・perMonth：期限があって、まだ達成していないとき。残りの月数は今月をふくむ（期限が今月なら 1）。
     *   1 か月あたり ＝ 残りの額 ÷ 残りの月数、1 円未満は切り上げ（切り捨てると、期限の月に少し足りなくなるため）
     * - overdue：期限の月が終わっていて、まだ達成していない
     *
     * @return array{goal: Goal, balance: int, percent: int, achieved: bool, overdue: bool, monthsLeft: int|null, perMonth: int|null}
     */
    public static function progress(Goal $goal, int $balance, CarbonImmutable $today): array
    {
        $target = $goal->target_amount;
        $achieved = $balance >= $target;
        // 小数を使わず、整数で切り捨てる（intdiv は 0 に近いほうへ切る。残高はここでは 0 以上）
        $percent = $balance <= 0 ? 0 : intdiv($balance * 100, $target);

        $monthsLeft = null;
        $perMonth = null;
        $overdue = false;
        if ($goal->deadline_month !== null && ! $achieved) {
            $thisMonth = $today->startOfMonth();
            $deadline = $goal->deadline_month->startOfMonth();
            if ($deadline->lessThan($thisMonth)) {
                $overdue = true;
            } else {
                $monthsLeft = ($deadline->year - $thisMonth->year) * 12 + ($deadline->month - $thisMonth->month) + 1;
                // 残りの額。残高がマイナスなら、その分も埋める必要があるので目標額より多くなる
                $rest = $target - $balance;
                $perMonth = intdiv($rest + $monthsLeft - 1, $monthsLeft);
            }
        }

        return [
            'goal' => $goal,
            'balance' => $balance,
            'percent' => $percent,
            'achieved' => $achieved,
            'overdue' => $overdue,
            'monthsLeft' => $monthsLeft,
            'perMonth' => $perMonth,
        ];
    }
}
