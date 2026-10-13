<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Enums\RecordType;
use App\Enums\ReserveType;
use Carbon\CarbonInterface;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 口座（データベース設計書 3.2）
 *
 * @property int $id
 * @property string $name
 * @property AccountType $type
 * @property int $initial_balance
 * @property ReserveType $reserve_type
 * @property int|null $reserve_amount
 * @property bool $is_active
 */
#[Fillable(['name', 'type', 'initial_balance', 'is_active'])]
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'initial_balance' => 'integer',
            'reserve_type' => ReserveType::class,
            'reserve_amount' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * この口座が「口座」（移動のときは「どこから」）になっている記録
     *
     * @return HasMany<Record, $this>
     */
    public function records(): HasMany
    {
        return $this->hasMany(Record::class);
    }

    /**
     * この口座が移動の「どこへ」になっている記録
     *
     * @return HasMany<Record, $this>
     */
    public function incomingTransfers(): HasMany
    {
        return $this->hasMany(Record::class, 'to_account_id');
    }

    /** この口座を使っている記録が 1 件でもあるか（削除・最初の残高の決まりに使う。画面設計書 5.6） */
    public function hasRecords(): bool
    {
        return $this->records()->exists() || $this->incomingTransfers()->exists();
    }

    /**
     * 「使わない」にできないときは、その理由の文を返す。できるときは null（画面設計書 5.6）
     * 残高が 0 円の口座だけ「使わない」にできる。今日 0 円でも、先の日付の記録で残高が変わる口座はできない
     *
     * @param  int|null  $initialBalance  変更の画面で、最初の残高も一緒に直すときの新しい値
     */
    public function deactivationBlocker(?int $initialBalance = null): ?string
    {
        $balance = $this->balance() - $this->initial_balance + ($initialBalance ?? $this->initial_balance);

        if ($balance !== 0) {
            return '残高が '.number_format($balance).' 円あるので『使わない』にできません。お金をほかの口座へ移す記録をつけるか、残高を合わせて 0 円にしてください';
        }

        $hasFutureRecords = Record::query()
            ->where(fn ($q) => $q->where('account_id', $this->id)->orWhere('to_account_id', $this->id))
            ->where('date', '>', today()->toDateString())
            ->exists();

        if ($hasFutureRecords) {
            return '先の日付の記録で残高が変わるので、『使わない』にできません';
        }

        return null;
    }

    /**
     * 一覧の並べ方：種類ごと（銀行口座 → 現金 → 交通系 IC → クレジットカード）、同じ種類の中は追加した順（画面設計書 5.6）
     *
     * @param  Builder<Account>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $order = collect(AccountType::cases())
            ->map(fn (AccountType $type, int $i) => "WHEN '{$type->value}' THEN {$i}")
            ->implode(' ');

        $query->orderByRaw("CASE type {$order} END")->orderBy('id');
    }

    /** この口座の残高。$until を省くと今日までの記録で計算する（先の日付の記録は、その日になったら入る） */
    public function balance(?CarbonInterface $until = null): int
    {
        return self::balances($until, [$this->id])[$this->id] ?? $this->initial_balance;
    }

    /**
     * 口座ごとの残高を、まとめて計算する（口座ごとに数えると、口座の数だけデータベースに聞くことになるため）
     * 残高 ＝ 最初の残高 ＋ 収入 − 支出 − 移動で出た分 ＋ 移動で入った分 ＋ 調整（データベース設計書 3.5）
     * 今の残高は保存せず、表示するたびにこうして計算する（データベース設計書 3.2）
     *
     * @param  CarbonInterface|null  $until  この日の終わりまでの記録で計算する。省くと今日
     * @param  list<int>|null  $ids  計算する口座。省くと全部
     * @return array<int, int> 口座の id → 残高
     */
    public static function balances(?CarbonInterface $until = null, ?array $ids = null): array
    {
        $until ??= today();

        // 口座（移動のときは「どこから」）から見た増減。支出と移動は出ていくのでマイナス
        $outgoing = Record::query()
            ->where('date', '<=', $until->toDateString())
            ->when($ids !== null, fn ($q) => $q->whereIn('account_id', $ids))
            ->groupBy('account_id')
            ->selectRaw('account_id, SUM(CASE WHEN type IN (?, ?) THEN -amount ELSE amount END) AS total', [
                RecordType::Expense->value,
                RecordType::Transfer->value,
            ])
            ->pluck('total', 'account_id');

        // 移動の「どこへ」から見た増減
        $incoming = Record::query()
            ->where('type', RecordType::Transfer)
            ->where('date', '<=', $until->toDateString())
            ->when($ids !== null, fn ($q) => $q->whereIn('to_account_id', $ids))
            ->groupBy('to_account_id')
            ->selectRaw('to_account_id, SUM(amount) AS total')
            ->pluck('total', 'to_account_id');

        return self::query()
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->pluck('initial_balance', 'id')
            ->map(fn ($initial, $id) => (int) $initial + (int) ($outgoing[$id] ?? 0) + (int) ($incoming[$id] ?? 0))
            ->all();
    }
}
