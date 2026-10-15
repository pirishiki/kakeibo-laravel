<?php

namespace App\Console\Commands;

use App\Support\RecurringRecorder;
use Illuminate\Console\Command;

/**
 * 日付が来た定期的な記録を記録する（データベース設計書 3.6）。毎日 0 時 5 分にスケジューラーが動かす（routes/console.php）。
 * 今日までの、まだ記録していない分を全部記録するので、動かなかった日の分もまとめて記録される。何度動かしても、同じ月の分は 1 回だけ。
 * 使い方：php artisan recurrings:record
 */
class RecordRecurrings extends Command
{
    protected $signature = 'recurrings:record';

    protected $description = '日付が来た定期的な記録を記録する（今日までのまだ記録していない分を全部）';

    public function handle(): int
    {
        $count = RecurringRecorder::recordAllDue();
        $this->info($count === 0 ? '記録するものはありませんでした' : $count.' 件を記録しました');

        return self::SUCCESS;
    }
}
