<?php

namespace App\Models;

use App\Enums\RecordType;
use Carbon\CarbonImmutable;
use Database\Factories\RecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
 */
#[Fillable(['date', 'type', 'amount', 'account_id', 'to_account_id', 'category_id', 'item', 'memo'])]
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
}
