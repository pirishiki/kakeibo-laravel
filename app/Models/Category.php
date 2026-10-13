<?php

namespace App\Models;

use App\Enums\CategoryKind;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * 分類（データベース設計書 3.3）。parent_id が空なら大分類、入っていれば小分類
 *
 * @property int $id
 * @property string $name
 * @property CategoryKind $kind
 * @property int|null $parent_id
 * @property int $sort_order
 * @property bool $is_active
 * @property-read Category|null $parent
 */
#[Fillable(['name', 'kind', 'parent_id', 'sort_order', 'is_active'])]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => CategoryKind::class,
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // 新しく作った分類は、同じ段のいちばん下に入れる（データベース設計書 3.3）
        static::creating(function (Category $category) {
            $category->sort_order ??= (int) self::query()->sameLevelAs($category)->max('sort_order') + 1;
        });
    }

    /**
     * 親の大分類（小分類のときだけ）
     *
     * @return BelongsTo<Category, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * 小分類（大分類のときだけ）。並び順のとおり
     *
     * @return HasMany<Category, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    /**
     * この分類が入っている記録
     *
     * @return HasMany<Record, $this>
     */
    public function records(): HasMany
    {
        return $this->hasMany(Record::class);
    }

    public function isParent(): bool
    {
        return $this->parent_id === null;
    }

    /**
     * 同じ段の分類：大分類なら同じ用の大分類どうし、小分類なら同じ大分類の中の小分類どうし
     *
     * @param  Builder<Category>  $query
     */
    public function scopeSameLevelAs(Builder $query, Category $category): void
    {
        $query->where('kind', $category->kind)
            ->when(
                $category->parent_id === null,
                fn ($q) => $q->whereNull('parent_id'),
                fn ($q) => $q->where('parent_id', $category->parent_id),
            );
    }

    /**
     * 同じ段のとなりの分類と、並び順を入れかえる（画面設計書 5.8 の ↑ ↓）。いちばん上で ↑、いちばん下で ↓ のときは何もしない
     */
    public function move(bool $up): void
    {
        DB::transaction(function () use ($up) {
            $neighbor = self::query()
                ->sameLevelAs($this)
                ->where('sort_order', $up ? '<' : '>', $this->sort_order)
                ->orderBy('sort_order', $up ? 'desc' : 'asc')
                ->lockForUpdate()
                ->first();

            if ($neighbor === null) {
                return;
            }

            [$this->sort_order, $neighbor->sort_order] = [$neighbor->sort_order, $this->sort_order];
            $this->save();
            $neighbor->save();
        });
    }
}
