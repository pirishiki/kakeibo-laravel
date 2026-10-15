<?php

namespace App\Models;

use App\Enums\RecordType;
use Carbon\CarbonImmutable;
use Database\Factories\RecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 記録：収入・支出・移動・調整（データベース設計書 3.5）。画面は記録のイシューで作る
 *
 * @property int $id
 * @property CarbonImmutable $date
 * @property RecordType $type
 * @property int $amount
 * @property int $account_id
 * @property int|null $to_account_id
 * @property int|null $category_id
 * @property int|null $partner_id
 * @property Carbon|null $updated_at
 * @property int|null $recurring_id どの定期的な記録から自動で作られたか。手で入れた記録は空
 * @property CarbonImmutable|null $recurring_month 何月の予定の分か（その月の 1 日）
 * @property-read Recurring|null $recurring
 */
#[Fillable(['date', 'type', 'amount', 'account_id', 'to_account_id', 'category_id', 'partner_id', 'item', 'memo'])]
class Record extends Model
{
    /** @use HasFactory<RecordFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'type' => RecordType::class,
            'amount' => 'integer',
            'recurring_month' => 'immutable_date',
        ];
    }

    /**
     * 口座（移動のときは「どこから」）
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * 移動のときの「どこへ」
     *
     * @return BelongsTo<Account, $this>
     */
    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'to_account_id');
    }

    /**
     * 分類（小分類を選んだときは小分類、選ばなかったときは大分類）
     *
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * この記録を自動で作った定期的な記録（手で入れた記録は空）
     *
     * @return BelongsTo<Recurring, $this>
     */
    public function recurring(): BelongsTo
    {
        return $this->belongsTo(Recurring::class);
    }

    /** 定期的な記録から自動で作られたか。金額などを直しても変わらない（画面設計書 5.3） */
    public function isAutomatic(): bool
    {
        return $this->recurring_id !== null;
    }

    /**
     * 相手
     *
     * @return BelongsTo<Partner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }
}
