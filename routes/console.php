<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 定期的な記録を、毎日 0 時 5 分（日本時間。config/app.php の timezone）に記録する（データベース設計書 3.6、全体構成設計書 5 章）。
// EC2 では systemd タイマーが毎分 schedule:run を動かし、時刻が来たときだけこれが動く。
// 前の回がまだ動いていたら、重ねて動かさない（同じ月の分を 2 回記録しない守りは、データベースのユニーク制約）。
// 途中で止まったときに残る鍵は 60 分で切れるようにする（最初の 24 時間のままだと、翌日の 0 時 5 分も動かないため）
Schedule::command('recurrings:record')->dailyAt('00:05')->withoutOverlapping(60);
