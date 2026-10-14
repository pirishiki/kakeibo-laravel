<?php

namespace Tests\Unit;

use App\Enums\RecurringShift;
use App\Support\RecurringDate;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 定期的な記録の「その月に記録する日」の計算（要件定義書 10.2「日付の計算」、N-20）
 * 月末・土日・祝日のずらし・連休・月や年をまたぐときを、決まった日付で確かめる
 */
class RecurringDateTest extends TestCase
{
    /**
     * @return array<string, array{int, RecurringShift, string, string}>
     */
    public static function cases(): array
    {
        return [
            // 平日はそのまま
            '平日はずらさない' => [15, RecurringShift::Next, '2026-10', '2026-10-15'],
            // 月末：30 日の月は 30 日、2 月は 28 日（うるう年は 29 日）
            '30日の月の月末は30日' => [RecurringDate::END_OF_MONTH, RecurringShift::Next, '2026-04', '2026-04-30'],
            'うるう年の2月の月末は29日' => [RecurringDate::END_OF_MONTH, RecurringShift::Next, '2028-02', '2028-02-29'],
            '2月の月末28日が土曜なら前の平日は27日' => [RecurringDate::END_OF_MONTH, RecurringShift::Previous, '2026-02', '2026-02-27'],
            // 土日は、選んだほうへずらす
            '日曜の給料は前の金曜' => [25, RecurringShift::Previous, '2026-10', '2026-10-23'],
            '28日の月曜はそのまま' => [28, RecurringShift::Next, '2026-12', '2026-12-28'],
            // 祝日が続くとき（大型連休・年末年始・敬老の日と秋分の日のあいだの休み）も、平日になるまでずらす
            '大型連休は休みが終わるまで次へずれる' => [3, RecurringShift::Next, '2026-05', '2026-05-07'],
            '振替休日から前へずらすと連休の前の金曜' => [6, RecurringShift::Previous, '2026-05', '2026-05-01'],
            '元日は三が日の土日もこえて次の平日' => [1, RecurringShift::Next, '2027-01', '2027-01-04'],
            '敬老の日から次へずらすと国民の休日と秋分の日もこえる' => [21, RecurringShift::Next, '2026-09', '2026-09-24'],
            '国民の休日から前へずらすと土日もこえる' => [22, RecurringShift::Previous, '2026-09', '2026-09-18'],
            // ずらして、となりの月・年になる
            '月末の土曜から次の平日で翌月' => [RecurringDate::END_OF_MONTH, RecurringShift::Next, '2026-10', '2026-11-02'],
            '2月の月末から次の平日で3月' => [RecurringDate::END_OF_MONTH, RecurringShift::Next, '2026-02', '2026-03-02'],
            '1日の日曜から前の平日で前の月' => [1, RecurringShift::Previous, '2026-11', '2026-10-30'],
            // 12月31日〜1月3日は、祝日ではないが銀行が休むので休みとして数える（データベース設計書 4 章）
            '元日から前の平日は大みそかもこえて12月30日' => [1, RecurringShift::Previous, '2027-01', '2026-12-30'],
            '1月2日の金曜は次の平日で1月5日' => [2, RecurringShift::Next, '2026-01', '2026-01-05'],
            '1月3日の平日は次の平日で1月4日' => [3, RecurringShift::Next, '2028-01', '2028-01-04'],
            '大みそかの平日は前の平日で12月30日' => [RecurringDate::END_OF_MONTH, RecurringShift::Previous, '2025-12', '2025-12-30'],
            '大みそかの平日は次の平日で年をまたぐ' => [RecurringDate::END_OF_MONTH, RecurringShift::Next, '2025-12', '2026-01-05'],
        ];
    }

    #[DataProvider('cases')]
    public function test_記録する日(int $day, RecurringShift $shift, string $month, string $expected): void
    {
        $date = RecurringDate::scheduled($day, $shift, CarbonImmutable::parse($month.'-01'));

        $this->assertSame($expected, $date->toDateString());
    }

    public function test_月のどの日を渡しても同じ月の日になる(): void
    {
        $this->assertSame('2026-10-23', RecurringDate::scheduled(25, RecurringShift::Previous, CarbonImmutable::parse('2026-10-31'))->toDateString());
    }

    public function test_日付の書き方(): void
    {
        $this->assertSame('25日', RecurringDate::dayLabel(25));
        $this->assertSame('月末', RecurringDate::dayLabel(RecurringDate::END_OF_MONTH));
    }
}
