<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 期限が切れたキャッシュの行を消す。毎日 0 時 10 分にスケジューラーが動かす（routes/console.php）。
 * 追加・登録の画面の「送信用の合言葉」（記録・定期的な記録は PreventsDoubleSubmit、口座・分類・相手は PreventsDoubleAdd。どちらも SubmitOnce を使う。期限 1 日）が、保存のたびにキャッシュに 1 行ずつ入る。
 * データベースのキャッシュは、期限が切れた行を自動では消さないので、放っておくと行が増え続け、バックアップにも入ってしまう。
 * 使い方：php artisan cache:prune-expired
 */
class PruneExpiredCache extends Command
{
    protected $signature = 'cache:prune-expired';

    protected $description = '期限が切れたキャッシュの行を消す（データベースのキャッシュ）';

    public function handle(): int
    {
        $table = (string) config('cache.stores.database.table', 'cache');
        // 期限（expiration）は、1970 年からの秒数。期限が切れた行だけを消す
        $count = DB::table($table)->where('expiration', '<=', now()->timestamp)->delete();
        $this->info($count.' 行を消しました');

        return self::SUCCESS;
    }
}
