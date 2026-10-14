<?php

namespace Database\Factories;

use App\Enums\RecordType;
use App\Enums\RecurringShift;
use App\Models\Account;
use App\Models\Recurring;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 定期的な記録のテスト用のデータ。最初は「毎月 27 日・次の平日・決まった金額 70,000 円の移動」（分類・相手のいらない形）
 *
 * @extends Factory<Recurring>
 */
class RecurringFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => '家賃',
            'type' => RecordType::Transfer,
            'day' => 27,
            'shift' => RecurringShift::Next,
            'months' => null,
            'is_fixed' => true,
            'amount' => 70000,
            'estimated_amount' => null,
            'account_id' => Account::factory(),
            'to_account_id' => Account::factory(),
            'start_month' => today()->startOfMonth(),
            'end_month' => null,
            'is_active' => true,
        ];
    }
}
