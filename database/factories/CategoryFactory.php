<?php

namespace Database\Factories;

use App\Enums\CategoryKind;
use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->lexify('分類????'),
            'kind' => CategoryKind::Expense,
            'is_active' => true,
        ];
    }

    /** この大分類の下の小分類にする */
    public function childOf(Category $parent): static
    {
        return $this->state(['parent_id' => $parent->id, 'kind' => $parent->kind]);
    }
}
