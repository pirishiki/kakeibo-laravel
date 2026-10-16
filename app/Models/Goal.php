<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 貯金の目標（データベース設計書 3.10）。貯めた額は持たず、選んだ口座の今の残高を使う。計算は app/Support/Goals.php
 *
 * @property int $id
 * @property string $name
 * @property int $target_amount
 * @property int $account_id
 * @property CarbonImmutable|null $deadline_month
 * @property Carbon|null $updated_at
 * @property-read Account $account
 */
#[Fillable(['name', 'target_amount', 'account_id', 'deadline_month'])]
class Goal extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_amount' => 'integer',
            'account_id' => 'integer',
            'deadline_month' => 'immutable_date',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * 一覧の並べ方：期限の近い順。期限なしは、その下に追加した順（画面設計書 5.13）
     *
     * @param  Builder<Goal>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderByRaw('deadline_month IS NULL')->orderBy('deadline_month')->orderBy('id');
    }

    /**
     * 変更の画面を開いたときの中身の印。保存のときに今の中身と比べて、開いたあとにほかの画面で直されていたら上書きしない。
     * 直した日時は秒までしかないので、同じ秒に 2 回直されても見分けられるように、中身もいっしょに入れる
     */
    public function fingerprint(): string
    {
        return md5((string) json_encode([
            $this->updated_at?->toDateTimeString(),
            $this->name,
            $this->target_amount,
            $this->account_id,
            $this->deadline_month?->toDateString(),
        ]));
    }

    /**
     * 中身が、送られた中身と同じか（保存を 2 回押して 2 回届いたかを見分ける）
     *
     * @param  array{name: string, target_amount: int, account_id: int, deadline_month: string|null}  $data
     */
    public function hasContent(array $data): bool
    {
        return $this->name === $data['name']
            && $this->target_amount === $data['target_amount']
            && $this->account_id === $data['account_id']
            && $this->deadline_month?->toDateString() === $data['deadline_month'];
    }

    /**
     * 目標が使っている口座を、削除・「使わない」・クレジットカードにしようとしたときの文（4 章の 9）。
     * $what は「削除できません」「『使わない』にできません」など
     */
    public function blockedMessage(string $what): string
    {
        return '貯金の目標「'.$this->name.'」がこの口座を使っているので、'.$what.'。先に目標の口座を変えるか、目標を削除してください';
    }
}
