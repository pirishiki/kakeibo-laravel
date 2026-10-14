<?php

namespace App\Support;

use App\Enums\RecurringShift;
use Carbon\CarbonImmutable;
use Yasumi\ProviderInterface;
use Yasumi\Yasumi;

/**
 * 定期的な記録の「その月に記録する日」の計算（データベース設計書 3.6、要件定義書 10.2「日付の計算」）
 * 1. 毎月の日付（1〜28、31＝月末）を、その月の日にする。月末は、その月の最後の日
 * 2. 土日・祝日なら、前の平日か次の平日へ、平日になるまでずらす（祝日は部品 Yasumi で調べる。データベース設計書 4 章）
 * 計算をここ 1 か所にまとめ、自動テストで確かめる（N-20）
 */
class RecurringDate
{
    /** 月末を表す日付（データベース設計書 3.6） */
    public const END_OF_MONTH = 31;

    /** @var array<int, ProviderInterface> 年ごとの祝日。同じ年を何度も計算しないように、とっておく */
    private static array $holidays = [];

    /**
     * その月に記録する日（ずらしたあとの日）。ずらした結果、となりの月の日になることもある（例：11月1日（日）→ 10月30日（金））
     *
     * @param  CarbonImmutable  $month  何月の分か（その月のどの日でもよい）
     */
    public static function scheduled(int $day, RecurringShift $shift, CarbonImmutable $month): CarbonImmutable
    {
        $first = $month->startOfMonth()->startOfDay();
        $date = $first->setDay(min($day, $first->daysInMonth));
        $step = $shift === RecurringShift::Previous ? -1 : 1;

        while (! self::isBusinessDay($date)) {
            $date = $date->addDays($step);
        }

        return $date;
    }

    /**
     * 平日（土日でも祝日でもない日）か。振替休日・国民の休日も休みとして数える。
     * 12月31日〜1月3日も、祝日ではないが銀行が休むので、休みとして数える（データベース設計書 4 章。2026-10-15 に本人と決定）
     */
    public static function isBusinessDay(CarbonImmutable $date): bool
    {
        if ($date->isWeekend() || self::isYearEndHoliday($date)) {
            return false;
        }

        $year = $date->year;
        self::$holidays[$year] ??= Yasumi::create('Japan', $year);

        return ! self::$holidays[$year]->isHoliday($date);
    }

    /** 年末年始の銀行の休み（12月31日・1月1日〜3日） */
    private static function isYearEndHoliday(CarbonImmutable $date): bool
    {
        return ($date->month === 12 && $date->day === 31) || ($date->month === 1 && $date->day <= 3);
    }

    /** 毎月の日付の書き方：「25日」「月末」 */
    public static function dayLabel(int $day): string
    {
        return $day === self::END_OF_MONTH ? '月末' : $day.'日';
    }
}
