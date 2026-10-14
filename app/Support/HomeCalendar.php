<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * ホームの今月のカレンダー（画面設計書 5.2）。日曜はじまりで、1 週間ずつのマス目を作る
 */
class HomeCalendar
{
    /**
     * その月のマス目。1 日より前と末日よりあとの空きマスは null
     *
     * @return list<list<CarbonImmutable|null>> 週ごとの、7 つのマス
     */
    public static function weeks(CarbonImmutable $month): array
    {
        $first = $month->startOfMonth();
        // 1 日の曜日の数だけ、前を空ける（日曜 0 〜 土曜 6）
        $cells = array_fill(0, $first->dayOfWeek, null);

        for ($day = $first; $day->isSameMonth($first); $day = $day->addDay()) {
            $cells[] = $day;
        }

        // 最後の週の、末日よりあとを空ける
        while (count($cells) % 7 !== 0) {
            $cells[] = null;
        }

        return array_chunk($cells, 7);
    }

    /**
     * スマホのマスに入れるための、短い金額。1 万円以上は「12.2万」の形（小数 1 けたまで、四捨五入。250,000 → 25万）
     */
    public static function shortAmount(int $amount): string
    {
        if (abs($amount) < 10000) {
            return number_format($amount);
        }

        // round のあとの .0 は、文字にすると消える（25.0 → "25"）
        return round($amount / 10000, 1).'万';
    }

    /**
     * カレンダーの日を押したときに、記録の一覧で動いていく先の日（画面設計書 5.2 のボタンと移り先）。
     * その日に記録があればその日、なければその日より前でいちばん近い記録のある日。どちらもないときは null（一覧のいちばん上）
     *
     * @param  list<string>  $recordDates  その月の、記録がある日（2026-10-09 の形。古い順）
     */
    public static function jumpDate(CarbonImmutable $day, array $recordDates): ?string
    {
        $target = null;

        foreach ($recordDates as $date) {
            if ($date > $day->toDateString()) {
                break;
            }
            $target = $date;
        }

        return $target;
    }
}
