<?php

namespace Database\Factories;

use App\Enums\RecordType;
use App\Models\Account;
use App\Models\Record;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Record>
 */
class RecordFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'date' => today(),
            'type' => RecordType::Expense,
            'amount' => 1000,
            'account_id' => Account::factory(),
        ];
    }
}
