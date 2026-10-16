<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * 予算（データベース設計書 3.9）。month が入っていれば特別な月だけの予算、空ならふだんの予算（start_month〜end_month）。
 * 月は、どれもその月の 1 日として持つ。計算と保存は app/Support/Budgets.php
 *
 * @property int $id
 * @property int $category_id
 * @property CarbonImmutable|null $month
 * @property CarbonImmutable|null $start_month
 * @property CarbonImmutable|null $end_month
 * @property int $amount
 */
#[Fillable(['category_id', 'month', 'start_month', 'end_month', 'amount'])]
class Budget extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category_id' => 'integer',
            'month' => 'immutable_date',
            'start_month' => 'immutable_date',
            'end_month' => 'immutable_date',
            'amount' => 'integer',
        ];
    }

    /**
     * その月に使われるふだんの予算：start_month がその月以前で、end_month が空かその月以後
     *
     * @param  Builder<Budget>  $query
     */
    public function scopeUsualFor(Builder $query, CarbonImmutable $month): void
    {
        $query->whereNull('month')
            ->where('start_month', '<=', $month->toDateString())
            ->where(fn ($q) => $q->whereNull('end_month')->orWhere('end_month', '>=', $month->toDateString()));
    }

    /**
     * その月だけの予算（特別な月）
     *
     * @param  Builder<Budget>  $query
     */
    public function scopeSpecialFor(Builder $query, CarbonImmutable $month): void
    {
        $query->where('month', $month->toDateString());
    }
}
