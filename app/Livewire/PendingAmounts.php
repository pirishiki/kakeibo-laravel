<?php

namespace App\Livewire;

use App\Models\Recurring;
use App\Support\FormInput;
use App\Support\RecurringMonthly;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * ホームのお知らせ「金額がまだのものが ○ 件あります」（画面設計書 5.2）。Livewire の部品
 * ふだんは 1 行で、押すとその場で入力欄が開く（Bootstrap の collapse）。まとめて保存・今回はなし。
 * お知らせが 1 つもないときは、カードごと出さない
 *
 * @property-read list<array{recurring: Recurring, month: CarbonImmutable, date: CarbonImmutable, estimate: int|null, label: string|null}> $items
 */
class PendingAmounts extends Component
{
    /** @var array<int|string, mixed> 金額の欄（「予定の id_年月」→ 打ったままの文字）。空の欄は保存しない */
    public array $amounts = [];

    /**
     * 金額がまだのもの。1 回の通信で 1 回だけ読む
     *
     * @return list<array{recurring: Recurring, month: CarbonImmutable, date: CarbonImmutable, estimate: int|null, label: string|null}>
     */
    #[Computed]
    public function items(): array
    {
        return RecurringMonthly::pending();
    }

    /** 欄の名前：「予定の id_年月」（例：5_202610） */
    public static function key(Recurring $recurring, CarbonImmutable $month): string
    {
        return $recurring->id.'_'.$month->format('Ym');
    }

    /** 入れた金額だけを保存してホームに戻る。日付が今日か過ぎていたものは、このときに予定の日付で記録する */
    public function save(): mixed
    {
        $values = [];
        foreach ($this->items as $item) {
            $key = self::key($item['recurring'], $item['month']);
            $text = $this->amounts[$key] ?? '';
            $values[$key] = FormInput::normalizeAmount(is_string($text) ? $text : '');
        }
        $this->amounts = array_replace($this->amounts, $values);
        [$rules, $messages] = MonthlyAmounts::amountRules(array_keys($values));
        $this->validate($rules, $messages);

        $saved = 0;
        foreach ($this->items as $item) {
            $text = $values[self::key($item['recurring'], $item['month'])];
            if ($text !== '') {
                RecurringMonthly::saveAmount($item['recurring'], $item['month'], (int) $text);
                $saved++;
            }
        }

        if ($saved > 0) {
            session()->flash('status', '保存しました');
        }

        return $this->redirect(route('home'));
    }

    /** その月は記録しないことにして、お知らせから消す */
    public function skip(string $key): void
    {
        foreach ($this->items as $item) {
            if (self::key($item['recurring'], $item['month']) === $key) {
                RecurringMonthly::skip($item['recurring'], $item['month']);
                unset($this->items, $this->amounts[$key]);

                return;
            }
        }
    }

    public function render(): View
    {
        return view('livewire.pending-amounts');
    }
}
