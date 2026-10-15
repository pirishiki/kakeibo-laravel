<?php

namespace App\Livewire;

use App\Models\Recurring;
use App\Support\FormInput;
use App\Support\RecurringMonthly;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
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

    /** 入れた金額を使えなかったときの知らせ。ないときは空 */
    public string $notice = '';

    /** 取っておく額を下回っている口座（画面設計書 5.2 のお知らせ）：id → 名前。ホームから渡す */
    #[Locked]
    public array $shortages = [];

    /** 入力欄を開いたまま出すか（「今回はなし」のあと、ホームを出し直したとき） */
    public bool $open = false;

    /**
     * 「今回はなし」などのあとにホームを出し直したときは、打ちかけの金額・知らせ・開いていたことを戻す
     *
     * @param  array<int, string>  $shortages
     */
    public function mount(array $shortages = []): void
    {
        $this->shortages = $shortages;
        $draft = session('pending.amounts');
        $this->amounts = is_array($draft) ? $draft : [];
        $notice = session('pending.notice');
        $this->notice = is_string($notice) ? $notice : '';
        $this->open = session('pending.open') === true;
    }

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
        // 欄が 1 つもないとき（全部記録済みになったときなど）は、チェックするものがない（空の決まりで validate すると、Livewire はエラーにする）
        if ($rules !== []) {
            $this->validate($rules, $messages);
        }

        $saved = 0;
        $problems = [];
        foreach ($this->items as $item) {
            $text = $values[self::key($item['recurring'], $item['month'])];
            if ($text !== '') {
                RecurringMonthly::saveAmount($item['recurring'], $item['month'], (int) $text);
                $problems[] = RecurringMonthly::recordProblem($item['recurring'], $item['month'], (int) $text);
                $saved++;
            }
        }
        $problems = array_values(array_filter($problems));

        // 開いたあとに、ほかの画面で記録されたか「今回はなし」になってお知らせから消えた予定に入れた金額は、使えない。黙らずに名前をつけて知らせる
        $unused = [];
        foreach ($this->amounts as $key => $text) {
            $key = (string) $key;
            if (! array_key_exists($key, $values) && FormInput::normalizeAmount(is_string($text) ? $text : '') !== ''
                && preg_match('/^(\d+)_\d{6}$/', $key, $m) === 1) {
                // 書きかえられた欄の名前（ない予定）は、名前が出せないので知らせない
                $name = Recurring::query()->whereKey((int) $m[1])->value('name');
                if (is_string($name)) {
                    $unused[] = $name;
                }
            }
        }
        if ($unused !== [] || $problems !== []) {
            $this->notice = implode(' ', [
                ...($unused === [] ? [] : [MonthlyAmounts::unusedNotice($unused).'金額は、定期的な記録の画面の「月ごとの金額」で確かめてください。']),
                ...$problems,
            ]);
            // いまのお知らせに残っている欄だけ、入れた金額を残す（保存できたもの・消えたものは覚えておかない）
            unset($this->items);
            $keys = array_map(fn (array $item) => self::key($item['recurring'], $item['month']), $this->items);
            $this->amounts = array_intersect_key($this->amounts, array_flip($keys));

            // 保存できたものがあれば、使える額・カレンダーも変わるので、ホームを出し直す（知らせと残りの欄は持っていく）
            return $saved > 0 ? $this->reloadHome() : null;
        }

        if ($saved > 0) {
            session()->flash('status', '保存しました');
        }

        return $this->redirect(route('home'));
    }

    /**
     * その月は記録しないことにして、お知らせから消す。
     * 使える額・カレンダーの予定も変わるので、ホームを出し直す（ほかの欄に打ちかけの金額は消さずに持っていき、欄も開いたままにする）
     */
    public function skip(string $key): mixed
    {
        $this->notice = '';
        foreach ($this->items as $item) {
            if (self::key($item['recurring'], $item['month']) === $key && RecurringMonthly::skip($item['recurring'], $item['month'])) {
                unset($this->amounts[$key]);

                return $this->reloadHome();
            }
        }

        // 開いたあとに、ほかの画面で記録されたか「今回はなし」になっていた。記録されていたら、残ったままなので知らせる
        $name = preg_match('/^(\d+)_\d{6}$/', $key, $m) === 1 ? Recurring::query()->whereKey((int) $m[1])->value('name') : null;
        if ($name !== null) {
            $this->notice = '「'.$name.'」は、開いたあとに記録されたか『今回はなし』になっていたので、今回はなしにはしていません。記録されていたら、定期的な記録の画面の「月ごとの金額」で確かめてください。';
        }
        unset($this->amounts[$key]);

        return $this->reloadHome();
    }

    /** ホームを出し直す。打ちかけの金額・知らせ・開いていたことは、次の画面に持っていく */
    private function reloadHome(): mixed
    {
        session()->flash('pending.amounts', array_filter($this->amounts, fn ($text) => is_string($text) && trim($text) !== ''));
        session()->flash('pending.notice', $this->notice);
        session()->flash('pending.open', true);

        return $this->redirect(route('home'));
    }

    public function render(): View
    {
        return view('livewire.pending-amounts');
    }
}
