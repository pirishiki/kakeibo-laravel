<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * 金額が変わる定期的な記録の、月ごとの金額（データベース設計書 3.7）。入力する画面は、月ごとの金額のかたまりで作る
 *
 * @property int $id
 * @property int $recurring_id
 * @property CarbonImmutable $month
 * @property int $amount
 */
#[Fillable(['recurring_id', 'month', 'amount'])]
class RecurringAmount extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['month' => 'immutable_date', 'amount' => 'integer'];
    }
}
