<?php

namespace App\Models;

use App\Enums\RecordType;
use App\Enums\RecurringShift;
use App\Support\RecurringDate;
use Carbon\CarbonImmutable;
use Database\Factories\RecurringFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 定期的な記録（データベース設計書 3.6）。給料・ローン・引き落としなど、毎月決まった日にある収入・支出・移動
 *
 * @property int $id
 * @property string $name
 * @property RecordType $type
 * @property int $day 1〜28、または 31（＝月末）
 * @property RecurringShift $shift
 * @property list<int>|null $months 選んだ月だけのときの月。空なら毎月
 * @property bool $is_fixed
 * @property int|null $amount
 * @property int|null $estimated_amount
 * @property int $account_id
 * @property int|null $to_account_id
 * @property int|null $category_id
 * @property int|null $partner_id
 * @property CarbonImmutable $start_month
 * @property CarbonImmutable|null $end_month
 * @property bool $is_active
 * @property-read Account $account
 * @property-read Account|null $toAccount
 */
#[Fillable(['name', 'type', 'day', 'shift', 'months', 'is_fixed', 'amount', 'estimated_amount', 'account_id', 'to_account_id',
    'category_id', 'partner_id', 'start_month', 'end_month', 'is_active'])]
class Recurring extends Model
{
    /** @use HasFactory<RecurringFactory> */
    use HasFactory;

    /** 「使う」で終わっていない予定が使っている口座・分類・相手を「使わない」にしようとしたときの文（画面設計書 4 章の 9） */
    public const IN_USE_MESSAGE = '「使う」の定期的な記録が使っているので、『使わない』にできません。先に下の定期的な記録を直してください';

    /** 予定が使っている口座・分類・相手を削除しようとしたときの文（記録がなくても削除できない。4 章の 9） */
    public const DELETE_BLOCKED_MESSAGE = '定期的な記録が使っているので、削除できません';

    /**
     * その口座を使っている予定（口座・どこから・どこへのどれか）
     *
     * @return Builder<Recurring>
     */
    public static function usingAccount(int $accountId): Builder
    {
        return self::query()->where(fn ($q) => $q->where('account_id', $accountId)->orWhere('to_account_id', $accountId));
    }

    /**
     * その分類を使っている予定。大分類なら、その下の小分類を使っているものもふくむ（大分類を「使わない」にすると、小分類も選べなくなるため）
     *
     * @return Builder<Recurring>
     */
    public static function usingCategory(Category $category): Builder
    {
        $ids = $category->isParent() ? [$category->id, ...$category->children()->pluck('id')->all()] : [$category->id];

        return self::query()->whereIn('category_id', $ids);
    }

    /**
     * その相手を使っている予定
     *
     * @return Builder<Recurring>
     */
    public static function usingPartner(int $partnerId): Builder
    {
        return self::query()->where('partner_id', $partnerId);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => RecordType::class,
            'day' => 'integer',
            'shift' => RecurringShift::class,
            // [6, 12] のような JSON の列を、PHP の配列として読み書きする（データベース設計書 3.6）
            'months' => 'array',
            'is_fixed' => 'boolean',
            'amount' => 'integer',
            'estimated_amount' => 'integer',
            'start_month' => 'immutable_date',
            'end_month' => 'immutable_date',
            'is_active' => 'boolean',
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
     * @return BelongsTo<Account, $this>
     */
    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'to_account_id');
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<Partner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /**
     * この予定から自動で作られた記録
     *
     * @return HasMany<Record, $this>
     */
    public function records(): HasMany
    {
        return $this->hasMany(Record::class);
    }

    /**
     * 今回はなしにした月（データベース設計書 3.8）
     *
     * @return HasMany<RecurringSkip, $this>
     */
    public function skips(): HasMany
    {
        return $this->hasMany(RecurringSkip::class);
    }

    /**
     * 月ごとの金額（データベース設計書 3.7）
     *
     * @return HasMany<RecurringAmount, $this>
     */
    public function amounts(): HasMany
    {
        return $this->hasMany(RecurringAmount::class);
    }

    /**
     * 「使う」で、まだ終わっていない予定（最後の月がないか、今月かそれより先）。
     * この予定が使っている口座・分類・相手は「使わない」にできない（要件定義書 10.2）
     *
     * @param  Builder<Recurring>  $query
     */
    public function scopeRunning(Builder $query): void
    {
        $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('end_month')->orWhere('end_month', '>=', today()->startOfMonth()->toDateString()));
    }

    /** 終わったか（最後の月の次の月の 1 日から。画面設計書 5.10） */
    public function isEnded(): bool
    {
        return $this->end_month !== null && $this->end_month->lt(today()->startOfMonth());
    }

    /** その月の分を記録する日（土日・祝日でずらしたあとの日） */
    public function scheduledDate(CarbonImmutable $month): CarbonImmutable
    {
        return RecurringDate::scheduled($this->day, $this->shift, $month);
    }

    /** その月が、この予定の月か（最初の月〜最後の月のあいだで、選んだ月だけなら選んだ月）。「使う」かどうかは見ない */
    public function coversMonth(CarbonImmutable $month): bool
    {
        $month = $month->startOfMonth();

        return $month->gte($this->start_month)
            && ($this->end_month === null || $month->lte($this->end_month))
            && ($this->months === null || in_array($month->month, $this->months, true));
    }

    /**
     * 最初の月の最初の値：記録する日（ずらしたあとの日）が、まだ過ぎていない（今日もふくむ）、いちばん早い月（画面設計書 5.10）
     * 先月から数える（例：10月31日（土）を次の平日にずらすと 11月2日になり、11月1日には、まだ 10 月の分が来ていない）
     *
     * @param  list<int>|null  $months  選んだ月だけのときの月
     */
    public static function firstUpcomingMonth(int $day, RecurringShift $shift, ?array $months, ?CarbonImmutable $today = null): CarbonImmutable
    {
        $today ??= today()->toImmutable();
        $month = $today->startOfMonth()->subMonth();

        // 選んだ月が 1 つでもあれば、13 か月のうちに必ず見つかる
        for ($i = 0; $i < 14; $i++, $month = $month->addMonth()) {
            if ($months !== null && ! in_array($month->month, $months, true)) {
                continue;
            }
            if (RecurringDate::scheduled($day, $shift, $month)->gte($today)) {
                return $month;
            }
        }

        return $today->startOfMonth();
    }

    /**
     * 保存すると、新しく「今回はなし」にする月（データベース設計書 3.6・3.8、画面設計書 5.10）
     * 保存したあとの内容で、記録する日が今日より前の月のうち、保存する前は記録の対象でなかった月
     * （新しく登録する・過ぎた月を最初の月に選ぶ・「使う」に戻す・最後の月を延ばす・最初の月を前にする）。
     * 記録済みの月と、もう「今回はなし」の月はのぞく
     *
     * 前から対象だった月の日付が、日付の変更で過ぎた日になったとき（今月の分）は、ここに入れない。
     * その月は、記録するか「今回はなし」かを小窓で聞く（自動で記録するかたまりで作る）
     *
     * @param  Recurring|null  $before  保存する前の内容。新しく登録するときは null
     * @return list<CarbonImmutable> 月（その月の 1 日）の並び。古い順
     */
    public function monthsToSkipOnSave(?Recurring $before, ?CarbonImmutable $today = null): array
    {
        if (! $this->is_active) {
            return [];
        }

        $today ??= today()->toImmutable();
        // 来月の分も、前の平日にずらすと今月の日になることがあるので、来月まで見る
        $last = $today->startOfMonth()->addMonth();
        $candidates = [];
        for ($month = $this->start_month; $month->lte($last); $month = $month->addMonth()) {
            $wasTarget = $before !== null && $before->is_active && $before->coversMonth($month);
            if (! $wasTarget && $this->coversMonth($month) && $this->scheduledDate($month)->lt($today)) {
                $candidates[] = $month;
            }
        }

        if ($candidates === [] || $before === null) {
            return $candidates;
        }

        // 記録済みの月・もう「今回はなし」の月は、入れ直さない
        $strings = array_map(fn (CarbonImmutable $m) => $m->toDateString(), $candidates);
        $done = array_merge(
            $before->records()->whereIn('recurring_month', $strings)->pluck('recurring_month')->all(),
            $before->skips()->whereIn('month', $strings)->pluck('month')->all(),
        );
        $done = array_map(fn ($m) => CarbonImmutable::parse($m)->toDateString(), $done);

        return array_values(array_filter($candidates, fn (CarbonImmutable $m) => ! in_array($m->toDateString(), $done, true)));
    }

    /**
     * 概算：前回の実際の金額（この予定から作られた、いちばん新しい記録）。まだ記録がないときは、登録したときの概算（データベース設計書 3.7）
     */
    public function estimate(): ?int
    {
        // 一覧では withLatestAmount() でいっしょに読んである
        $latest = array_key_exists('latest_amount', $this->attributes)
            ? $this->attributes['latest_amount']
            : $this->latestRecordAmount()->value('amount');

        return $latest !== null ? (int) $latest : $this->estimated_amount;
    }

    /**
     * 前回の実際の金額を、予定といっしょに読む（latest_amount として入る）
     *
     * @param  Builder<Recurring>  $query
     */
    public function scopeWithLatestAmount(Builder $query): void
    {
        $query->addSelect(['latest_amount' => Record::query()
            ->select('amount')
            ->whereColumn('recurring_id', 'recurrings.id')
            ->orderByDesc('recurring_month')
            ->orderByDesc('id')
            ->limit(1)]);
    }

    /**
     * この予定から作られた、いちばん新しい記録（何月の分が新しい順）
     *
     * @return HasMany<Record, $this>
     */
    private function latestRecordAmount(): HasMany
    {
        return $this->records()->orderByDesc('recurring_month')->orderByDesc('id');
    }

    /** 一覧の 2 行目の日付：「毎月25日（前の平日）」「6月・12月の10日（次の平日）」 */
    public function scheduleLabel(): string
    {
        $when = $this->months === null
            ? '毎月'
            : implode('・', array_map(fn (int $m) => $m.'月', $this->months)).'の';

        return $when.RecurringDate::dayLabel($this->day).'（'.$this->shift->label().'）';
    }
}
