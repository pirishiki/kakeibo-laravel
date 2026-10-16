<?php

namespace App\Livewire;

use App\Models\Recurring;
use App\Support\FormInput;
use App\Support\RecurringMonthly;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * 月ごとの金額（画面設計書 5.10 前半）。Livewire の部品
 * その月の分の予定を並べ、金額が変わる予定の金額を入れる・直す。今回はなし・もどすもここで押す。
 * 月の切りかえ（◀▶）は、記録の一覧と同じく URL の ?month= で行う（app/Http/Controllers/RecurringController.php）
 *
 * @property-read list<array{recurring: Recurring, month: CarbonImmutable, date: CarbonImmutable, reason: string|null, status: string, amount: int|null, estimate: int|null, recordId: int|null}> $rows
 */
class MonthlyAmounts extends Component
{
    /** 見ている月（その月の 1 日）。画面の側から書きかえられないようにする */
    #[Locked]
    public string $month;

    /** @var array<int|string, mixed> 金額が変わる予定の金額の欄（予定の id → 打ったままの文字） */
    public array $amounts = [];

    /**
     * @var array<int, string> 開いたときの金額（予定の id → 数字だけの文字）。保存のときは、ここから変わった欄だけを保存する
     *                         （開いたままの古い画面で保存して、ほかの画面で入れた金額を消さないため）。画面の側から書きかえられないようにする
     */
    #[Locked]
    public array $opened = [];

    /** 入れた金額を使えなかったときの知らせ（画面の上に出す）。ないときは空 */
    public string $notice = '';

    public function mount(CarbonImmutable $month): void
    {
        $this->month = $month->startOfMonth()->toDateString();
        foreach ($this->rows as $row) {
            if ($row['status'] === RecurringMonthly::VARIABLE) {
                $this->fillAmount($row['recurring']->id, $row['amount']);
            }
        }
    }

    /**
     * その月の分の予定の行。1 回の通信で 1 回だけ読む
     *
     * @return list<array{recurring: Recurring, month: CarbonImmutable, date: CarbonImmutable, reason: string|null, status: string, amount: int|null, estimate: int|null, recordId: int|null}>
     */
    #[Computed]
    public function rows(): array
    {
        return RecurringMonthly::rows(CarbonImmutable::parse($this->month));
    }

    /** 全部の金額の欄をまとめて保存する。日付が今日か過ぎていたものは、予定の日付ですぐに記録する */
    public function save(): mixed
    {
        $this->notice = '';
        // 欄が出ていて、開いたときから金額を変えた行だけ
        $variable = array_filter($this->rows, fn (array $row) => $row['status'] === RecurringMonthly::VARIABLE
            && array_key_exists($row['recurring']->id, $this->amounts));
        $values = [];
        foreach ($variable as $row) {
            $text = $this->amounts[$row['recurring']->id] ?? '';
            $values[$row['recurring']->id] = FormInput::normalizeAmount(is_string($text) ? $text : '');
        }
        $this->amounts = array_replace($this->amounts, $values);
        [$rules, $messages] = self::amountRules(array_keys($values));
        // 欄が 1 つもないとき（全部記録済みになったときなど）は、チェックするものがない（空の決まりで validate すると、Livewire はエラーにする）
        if ($rules !== []) {
            $this->validate($rules, $messages);
        }

        // 全部の行を 1 つのトランザクションで保存する（途中の行で、ほかの画面の保存と押さえあってやり直しを使い切ったときに、
        // 前の行だけ保存・記録されたのに「保存できませんでした」と出さないように。全部やるか、何もしないか）
        $changed = array_values(array_filter($variable, fn (array $row) => $values[$row['recurring']->id] !== ($this->opened[$row['recurring']->id] ?? '')));
        DB::transaction(function () use ($changed, $values) {
            // 先に、保存する予定の行を全部、id の小さい順に押さえる（押さえる順番の決まり：予定→記録→口座。1 行目の記録・口座を押さえたまま
            // 2 行目の予定を押さえに行くと、残高を合わせる画面などと、たがいを待ちつづけることがあるため）
            RecurringMonthly::lockForSaving(array_map(fn (array $row) => $row['recurring'], $changed));
            foreach ($changed as $row) {
                $text = $values[$row['recurring']->id];
                RecurringMonthly::saveAmount($row['recurring'], $row['month'], $text === '' ? null : (int) $text);
            }
        }, 3);

        // 記録できたかの確かめは、保存し終わってから、いちばん新しい中身で行う（トランザクションの中だと、最初に読んだ時点の古い中身を見て、
        // ほかの画面・自動の記録で前の金額で記録されていたことに気づかず、「保存しました」と出してしまうため）
        $problems = [];
        foreach ($changed as $row) {
            $text = $values[$row['recurring']->id];
            $problems[] = $text === ''
                ? ($row['recurring']->records()->where('recurring_month', $row['month']->toDateString())->exists()
                    ? '「'.$row['recurring']->name.'」は、開いたあとに記録されていたので、金額を消していません。記録済みの金額を押して記録を直してください。'
                    : null)
                : RecurringMonthly::recordProblem($row['recurring'], $row['month'], (int) $text);
        }
        $problems = array_values(array_filter($problems));

        // 開いたあとに、ほかで記録されたか「今回はなし」になって欄がなくなった行で、金額を変えていたもの（例：0 時 5 分の自動の記録）。
        // 入れた金額は使えないので、黙って「保存しました」とせず、名前をつけて知らせる（残高が知らないうちにずれないように）
        $unused = [];
        foreach ($this->rows as $row) {
            $id = $row['recurring']->id;
            if ($row['status'] !== RecurringMonthly::VARIABLE && array_key_exists($id, $this->opened)) {
                $text = $this->amounts[$id] ?? '';
                if (FormInput::normalizeAmount(is_string($text) ? $text : '') !== $this->opened[$id]) {
                    $unused[] = $row['recurring']->name;
                }
                unset($this->amounts[$id], $this->opened[$id]);
            }
        }
        if ($unused !== [] || $problems !== []) {
            $this->notice = implode(' ', [...($unused === [] ? [] : [self::unusedNotice($unused).'記録済みのものは、金額を押して記録を直してください。']), ...$problems]);
            unset($this->rows);
            // 欄を、いまの状態で覚え直す（もう一度「保存」を押したとき、保存した欄や、記録済みになった欄を「使っていない」と言わないように）
            $this->refillAmounts();

            return null;
        }

        session()->flash('status', '保存しました');

        return $this->redirect(route('recurrings.index', ['month' => CarbonImmutable::parse($this->month)->format('Y-m')]));
    }

    /** その行を「今回はなし」にする（記録される前の行だけ） */
    public function skip(int $recurringId): void
    {
        $this->notice = '';
        $row = $this->row($recurringId, [RecurringMonthly::FIXED, RecurringMonthly::VARIABLE]);
        if ($row === null) {
            $this->changedNotice($recurringId, '今回はなしにできませんでした');

            return;
        }

        if (! RecurringMonthly::skip($row['recurring'], $row['month'])) {
            $this->changedNotice($recurringId, '今回はなしにできませんでした');

            return;
        }
        unset($this->rows);
        // 今回はなしにした行の欄は、もう出さないので覚えておかない（次の保存で「使っていない」と言わないように）
        unset($this->amounts[$recurringId], $this->opened[$recurringId]);
    }

    /** 「今回はなし」をやめて元に戻す。日付が今日か過ぎていて金額があれば、すぐに記録する */
    public function restore(int $recurringId): void
    {
        $this->notice = '';
        $row = $this->row($recurringId, [RecurringMonthly::SKIPPED]);
        if ($row === null) {
            $this->changedNotice($recurringId, 'もどせませんでした');

            return;
        }

        RecurringMonthly::restore($row['recurring'], $row['month']);
        $this->notice = (string) RecurringMonthly::recordProblem($row['recurring'], $row['month']);
        unset($this->rows);
        // 金額が変わる予定で、まだ記録されていなければ、入れてある金額を欄に出す
        foreach ($this->rows as $fresh) {
            if ($fresh['recurring']->id === $recurringId && $fresh['status'] === RecurringMonthly::VARIABLE) {
                $this->fillAmount($recurringId, $fresh['amount']);
            }
        }
    }

    public function render(): View
    {
        return view('livewire.monthly-amounts');
    }

    /**
     * 入れた金額を使えなかった予定の知らせ（月ごとの金額とホームのお知らせで共通）
     *
     * @param  list<string>  $names
     */
    public static function unusedNotice(array $names): string
    {
        return '「'.implode('」「', array_values(array_unique($names))).'」は、開いたあとに記録されたか『今回はなし』になっていたので、入れた金額は使っていません。';
    }

    /**
     * 金額の欄のチェック：空でもよい。入れるときは 1 円以上の整数（画面設計書 5.10・5.2）。ホームのお知らせと共通
     *
     * @param  list<int|string>  $keys  欄の名前（amounts.<この値>）
     * @return array{0: array<string, list<string>>, 1: array<string, string>}
     */
    public static function amountRules(array $keys): array
    {
        $rules = [];
        $messages = [];
        foreach ($keys as $key) {
            $rules['amounts.'.$key] = ['nullable', 'integer', 'min:1', 'max:'.FormInput::MAX_AMOUNT];
            $messages['amounts.'.$key.'.integer'] = '金額は、数字だけで入れてください。';
            $messages['amounts.'.$key.'.min'] = '金額は 1 円以上で入れてください。';
            $messages['amounts.'.$key.'.max'] = '金額は 99,999,999 円までで入れてください。';
        }

        return [$rules, $messages];
    }

    /**
     * 押したボタンの行が、開いたあとにほかで変わっていた（記録された・今回はなしになった・もどされた）ときの知らせ。
     * 黙って何もしないと、押したのに変わらない理由がわからないため
     */
    private function changedNotice(int $recurringId, string $what): void
    {
        $name = Recurring::query()->whereKey($recurringId)->value('name');
        $this->notice = '「'.$name.'」は、開いたあとに記録されたか、ほかの画面で変わっていたので、'.$what.'。いまの状態を下に出しています。';
        unset($this->rows);
    }

    /** いまの行で、金額の欄を覚え直す。欄が出ていない行（記録済み・今回はなし）は覚えておかない */
    private function refillAmounts(): void
    {
        $this->amounts = [];
        $this->opened = [];
        foreach ($this->rows as $row) {
            if ($row['status'] === RecurringMonthly::VARIABLE) {
                $this->fillAmount($row['recurring']->id, $row['amount']);
            }
        }
    }

    /** 金額の欄に、保存してある金額を入れる（開いたときの金額としても覚える） */
    private function fillAmount(int $recurringId, ?int $amount): void
    {
        $this->amounts[$recurringId] = $amount === null ? '' : number_format($amount);
        $this->opened[$recurringId] = $amount === null ? '' : (string) $amount;
    }

    /**
     * 画面に出ている行のうち、その予定の行（状態が合うときだけ）
     *
     * @param  list<string>  $statuses
     * @return array{recurring: Recurring, month: CarbonImmutable, date: CarbonImmutable, reason: string|null, status: string, amount: int|null, estimate: int|null, recordId: int|null}|null
     */
    private function row(int $recurringId, array $statuses): ?array
    {
        foreach ($this->rows as $row) {
            if ($row['recurring']->id === $recurringId && in_array($row['status'], $statuses, true)) {
                return $row;
            }
        }

        return null;
    }
}
