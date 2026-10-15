<?php

namespace App\Livewire;

use App\Enums\RecordType;
use App\Models\Account;
use App\Models\Record;
use App\Support\FormInput;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * 残高を合わせる（画面設計書 5.7、要件定義書 No.4）。Livewire の部品
 *
 * 実際の金額を入れると、アプリの残高との差をすぐに出す。保存すると、差の金額で「調整」の記録を 1 つ作る（最初の残高は書きかえない）
 *
 * @property-read int $appBalance
 * @property-read int|null $difference
 * @property-read string|null $tooLargeMessage
 */
class AdjustBalance extends Component
{
    private const TOO_LARGE = '差が大きすぎます。一度に合わせられるのは、差が 99,999,999 円までです。';

    /** 合わせる口座。画面の側から書きかえられないようにする（ほかの口座に調整を作られないように） */
    #[Locked]
    public int $accountId;

    /** 実際の金額。打ったままの文字で持つ（「8,500」や全角の数字、マイナスも受けとれるように） */
    public string $actual = '';

    public string $date = '';

    public string $memo = '';

    /** 保存したあとに戻る画面（記録の部品と同じく、書きかえられないようにする） */
    #[Locked]
    public string $backUrl = '';

    public function mount(Account $account): void
    {
        // 「使わない」口座はいつも 0 円のまま、という前提を守るため、合わせられない（画面設計書 5.6）
        abort_unless($account->is_active, 404);

        // 戻る先：「＋ つけ忘れを記録する」から戻ってきたときも、最初に開く前の画面へ戻れるように、URL の back を先に使う
        $back = request()->query('back');

        $this->accountId = $account->id;
        $this->date = today()->toDateString();
        $this->backUrl = FormInput::backUrl(
            is_string($back) && $back !== '' ? $back : url()->previous(),
            ['#^/accounts/\d+/adjust#', '#^/records/create#', '#^/records/\d+/edit#'],
        );
    }

    /**
     * アプリの残高：選んだ日付の終わりまでの記録で計算する（最初は今日。前の日にすると、その日までの残高）
     */
    #[Computed]
    public function appBalance(): int
    {
        $until = FormInput::isDate($this->date) ? CarbonImmutable::parse($this->date) : today();

        return $this->account()->balance($until);
    }

    /**
     * 差 ＝ 実際の金額 − アプリの残高。実際の金額がまだ数の形でないときは null
     */
    #[Computed]
    public function difference(): ?int
    {
        $actual = FormInput::normalizeAmount($this->actual);

        return preg_match('/^-?\d{1,9}$/', $actual) === 1 ? (int) $actual - $this->appBalance : null;
    }

    /** 画面に出す、差が大きすぎるときの文（ボタンも押せなくする） */
    #[Computed]
    public function tooLargeMessage(): ?string
    {
        return $this->differenceTooLarge() ? self::TOO_LARGE : null;
    }

    /**
     * つけ忘れがないかを見るための、この口座の最近の記録 10 件（移動の「どこへ」もふくむ。新しい順）
     *
     * @return Collection<int, Record>
     */
    #[Computed]
    public function recentRecords(): Collection
    {
        return Record::query()
            ->where(fn ($q) => $q->where('account_id', $this->accountId)->orWhere('to_account_id', $this->accountId))
            ->with(['account', 'toAccount', 'category.parent', 'partner'])
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit(10)
            ->get();
    }

    public function save(): mixed
    {

        $this->actual = FormInput::normalizeAmount($this->actual);
        $max = FormInput::MAX_AMOUNT;

        $this->validate([
            'actual' => ['required', 'integer', "min:-{$max}", "max:{$max}"],
            // 先の日付は選べない（数える前の金額は、まだわからないため）
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.FormInput::FIRST_DATE, 'before_or_equal:'.today()->toDateString()],
            'memo' => ['nullable', 'string', 'max:1000'],
        ], [
            'actual.required' => '実際の金額を入れてください。',
            'actual.integer' => '実際の金額は、数字だけで入れてください。',
            'actual.min' => '実際の金額は -99,999,999 円から 99,999,999 円までで入れてください。',
            'actual.max' => '実際の金額は -99,999,999 円から 99,999,999 円までで入れてください。',
            'date.required' => '日付を入れてください。',
            'date.date_format' => '日付の形がちがいます。',
            'date.after_or_equal' => '日付は 2000年1月1日 から今日までで入れてください。',
            'date.before_or_equal' => '日付は 2000年1月1日 から今日までで入れてください。',
            'memo.max' => 'メモは 1,000 文字以内で入れてください。',
        ]);

        // 0 円の調整の記録はつくらない（データベース設計書 3.5）。画面ではボタンを押せなくしているが、念のためここでも止める
        $difference = $this->difference;
        if ($difference === null || $difference === 0) {
            $this->addError('actual', 'ずれはありません。');

            return null;
        }
        // 差は調整の記録の金額になる。記録の金額の上限をこえると、あとで直せない記録になるので止める
        if ($this->differenceTooLarge()) {
            $this->addError('actual', self::TOO_LARGE);

            return null;
        }

        $error = DB::transaction(function () {
            // 口座を押さえてから、「使う」か・差をもう一度確かめる。
            // 画面を開いたあとに、ほかのタブで「使わない」にされたときや、ほかのタブで先に合わせたとき（差が変わる）に、二重に調整しないため
            $account = Account::query()->whereKey($this->accountId)->lockForUpdate()->firstOrFail();
            if (! $account->is_active) {
                return 'この口座は、ほかの画面で『使わない』にされたので、合わせられません。';
            }
            unset($this->appBalance, $this->difference);
            $difference = $this->difference;
            if ($difference === null || $difference === 0) {
                return 'ずれはありません。（ほかの画面で、先に合わせてあります）';
            }
            if ($this->differenceTooLarge()) {
                return self::TOO_LARGE;
            }

            Record::create([
                'date' => $this->date,
                'type' => RecordType::Adjustment,
                'amount' => $difference,
                'account_id' => $this->accountId,
                'memo' => $this->memo !== '' ? $this->memo : null,
            ]);

            return null;
        }, 3);
        if ($error !== null) {
            $this->addError('actual', $error);

            return null;
        }

        session()->flash('status', '保存しました');

        return $this->redirect($this->backUrl);
    }

    public function render(): View
    {
        return view('livewire.adjust-balance', ['account' => $this->account()]);
    }

    /** 差が、記録の金額に入れられる大きさ（±99,999,999 円）をこえているか */
    private function differenceTooLarge(): bool
    {
        return $this->difference !== null && abs($this->difference) > FormInput::MAX_AMOUNT;
    }

    private function account(): Account
    {
        return Account::query()->findOrFail($this->accountId);
    }
}
