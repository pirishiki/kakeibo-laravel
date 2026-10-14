<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PartnerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 相手（データベース設計書 3.4）
 *
 * @property int $id
 * @property string $name
 * @property bool $is_active
 * @property string|null $records_max_date 最後に使った日（withMax('records', 'date') で読んだときだけ入る）
 */
#[Fillable(['name', 'is_active'])]
class Partner extends Model
{
    /** @use HasFactory<PartnerFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * この相手の記録
     *
     * @return HasMany<Record, $this>
     */
    public function records(): HasMany
    {
        return $this->hasMany(Record::class);
    }

    /**
     * 相手の名前の形をそろえる：全角の空白を半角に、続いた空白を 1 つに、前後の空白を取る。
     * 相手の管理・記録の登録・まとめ先のどこで打っても、同じ形で保存して探すため（「セブン　イレブン」と「セブン イレブン」が別の相手にならないように）
     */
    public static function normalizeName(string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', mb_convert_kana($name, 's')));
    }

    /** 最後に使った日（いちばん新しい記録の日付）。まだ使っていなければ null */
    public function lastUsedOn(): ?CarbonImmutable
    {
        return $this->records_max_date !== null ? CarbonImmutable::parse($this->records_max_date) : null;
    }

    /**
     * 一覧に出す 3 つのまとまり（画面設計書 5.9）。最後に使った日は保存せず、記録から計算する
     * - used：使った相手を、最近使った順（最後に使った日が新しい順。同じ日は追加した順）
     * - unused：まだ使っていない相手を、追加した順
     * - inactive：「使わない」相手を、最近使った順
     *
     * @param  Builder<Partner>|null  $query  しぼりこんだ相手だけで分けるとき（記録の登録の相手の候補など）
     * @return array{used: Collection<int, Partner>, unused: Collection<int, Partner>, inactive: Collection<int, Partner>}
     */
    public static function listGroups(?Builder $query = null): array
    {
        $partners = ($query ?? self::query())->withMax('records', 'date')->orderBy('id')->get();
        $recent = fn (Collection $group) => $group->sortByDesc('records_max_date', SORT_STRING)->values();
        [$active, $inactive] = $partners->partition(fn (Partner $p) => $p->is_active);

        return [
            'used' => $recent($active->whereNotNull('records_max_date')),
            'unused' => $active->whereNull('records_max_date')->values(),
            'inactive' => $recent($inactive),
        ];
    }

    /**
     * この相手の記録と定期的な記録を、まとめ先の相手へ移して、この相手を削除する（画面設計書 5.9「ほかの相手にまとめる」）
     * 全部やるか、何もしないか（トランザクション）。2 つの相手の行を押さえてから、まとめ先が「使う」で自分でないかを確かめ直す
     * （確かめたあと、別の画面でまとめ先を「使わない」にしたときなどに、まちがってまとめないため）
     *
     * @return bool まとめたら true。まとめ先が「使わない」・自分・なくなっていたら、何もせずに false
     */
    public function mergeInto(Partner $target): bool
    {
        return DB::transaction(function () use ($target) {
            $locked = self::query()->whereKey([$this->id, $target->id])->lockForUpdate()->get()->keyBy('id');
            $fresh = $locked->get($target->id);

            if ($target->id === $this->id || $fresh === null || ! $fresh->is_active || ! $locked->has($this->id)) {
                return false;
            }

            Record::query()->where('partner_id', $this->id)->update(['partner_id' => $target->id]);
            // この相手を使っている定期的な記録も、まとめ先へ移す（要件定義書 10.2）
            Recurring::query()->where('partner_id', $this->id)->update(['partner_id' => $target->id]);
            self::query()->whereKey($this->id)->delete();

            return true;
        });
    }
}
