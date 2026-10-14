<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * 定期的な記録の「今回はなし」の月（データベース設計書 3.8）
 *
 * @property int $id
 * @property int $recurring_id
 * @property CarbonImmutable $month
 */
#[Fillable(['recurring_id', 'month'])]
class RecurringSkip extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['month' => 'immutable_date'];
    }
}
