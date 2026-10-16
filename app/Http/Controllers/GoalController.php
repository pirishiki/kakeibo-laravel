<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Http\Requests\GoalRequest;
use App\Models\Account;
use App\Models\Goal;
use App\Support\Goals;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * 貯金の目標（画面設計書 5.13）：一覧・追加・変更・削除。
 * 目標の番号（{goal}）は、Laravel に探させずに自分で探す。ほかの画面で削除された目標を開いた・保存したときに、
 * エラーの画面（404）ではなく、一覧に戻って理由を出すため
 */
class GoalController extends Controller
{
    /** ほかの画面で削除された目標を開いた・保存した・削除したときの知らせ */
    private const DELETED_MESSAGE = 'この目標は、ほかの画面で削除されていました。';

    /** 削除された目標に打った内容を、追加の画面へ持っていったときの知らせ */
    private const KEPT_INPUT_MESSAGE = '打った内容を入れておいたので、保存すると新しい目標として追加できます。';

    public function index(): View
    {
        // ホームと同じく、1 つのトランザクションの中で読む（読んでいるあいだに移動の記録などが入っても、片方の口座にだけ入った残高を出さないように）
        $rows = collect(DB::transaction(fn () => Goals::list(CarbonImmutable::today())));

        return view('goals.index', [
            'active' => $rows->where('achieved', false),
            'achieved' => $rows->where('achieved', true),
        ]);
    }

    public function create(): View
    {
        return view('goals.create', $this->formData(new Goal));
    }

    public function store(GoalRequest $request): RedirectResponse
    {
        $data = $request->goalData();
        try {
            DB::transaction(function () use ($data) {
                $account = Account::query()->lockForUpdate()->find($data['account_id']);
                // 保存を 2 回押して 2 回届いたとき：1 回目で同じ中身の目標ができていれば、2 回目は作らずに「保存しました」にする
                $existing = Goal::query()->where('account_id', $data['account_id'])->first();
                if ($existing !== null && $existing->hasContent($data)) {
                    return;
                }
                $this->checkAccount($account, null);
                Goal::create($data);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            // チェックのあと、保存までに同じ口座の目標ができていた（2 つの画面から同時に保存したなど）
            return $this->accountTaken($data['account_id'], null);
        }

        return to_route('goals.index')->with('status', '保存しました');
    }

    public function edit(string $goal): View|RedirectResponse
    {
        $found = Goal::query()->find((int) $goal);
        if ($found === null) {
            // 時間切れ（419）・ログインし直し・入力のまちがいで、打った内容を持ってこの画面に戻ってきたときは、それを捨てずに追加の画面へ持っていく
            if (session()->hasOldInput()) {
                return to_route('goals.create')
                    ->withInput(Arr::except((array) session()->getOldInput(), ['opened_at', '_token', '_method']))
                    ->with('warning', self::DELETED_MESSAGE.self::KEPT_INPUT_MESSAGE);
            }

            return to_route('goals.index')->with('warning', self::DELETED_MESSAGE);
        }

        return view('goals.edit', $this->formData($found));
    }

    public function update(GoalRequest $request, string $goal): RedirectResponse
    {
        $data = $request->goalData();
        $opened = $request->input('opened_at');
        try {
            $result = DB::transaction(function () use ($goal, $data, $opened) {
                // 目標 → 口座の順に押さえる
                $locked = Goal::query()->lockForUpdate()->find((int) $goal);
                if ($locked === null) {
                    return 'deleted';
                }
                // 保存を 2 回押して 2 回届いたとき：今の中身が送った中身と同じなら、もう保存できているので何もしない
                if ($locked->hasContent($data)) {
                    return 'saved';
                }
                // 開いたあとに、ほかの画面でこの目標が直されていたら、古い画面の中身で上書きしない
                if (is_string($opened) && $locked->fingerprint() !== $opened) {
                    // 打った内容は欄に残し、今の内容を文で見せる（画面の印は今の中身のものになるので、もう一度保存すると打った内容で上書きできる）
                    $locked->load('account');
                    throw ValidationException::withMessages(['save' => 'この目標は、開いたあとにほかの画面で直されたので、保存していません。今の内容は「'
                        .$locked->name.'・'.number_format($locked->target_amount).' 円・'.$locked->account->name.'・'
                        .($locked->deadline_month !== null ? $locked->deadline_month->format('Y年n月').'まで' : '期限なし')
                        .'」です。打った内容は欄に残しているので、確かめてからもう一度保存すると、打った内容で上書きします']);
                }
                if ($locked->account_id !== $data['account_id']) {
                    $this->checkAccount(Account::query()->lockForUpdate()->find($data['account_id']), $locked->id);
                }
                $locked->update($data);

                return 'saved';
            }, 3);
        } catch (UniqueConstraintViolationException) {
            return $this->accountTaken($data['account_id'], (int) $goal);
        }

        if ($result === 'deleted') {
            // 打った内容を捨てないように、追加の画面へ持っていく（そのまま保存すれば、新しい目標として追加できる）
            return to_route('goals.create')
                ->withInput($request->except(['opened_at', '_token', '_method']))
                ->with('warning', self::DELETED_MESSAGE.'保存していません。'.self::KEPT_INPUT_MESSAGE);
        }

        return to_route('goals.index')->with('status', '保存しました');
    }

    /** 削除。目標には記録がつかないので、いつでも削除できる。口座と残高はそのまま（画面設計書 5.13） */
    public function destroy(string $goal): RedirectResponse
    {
        $deleted = Goal::query()->whereKey((int) $goal)->delete();
        if ($deleted === 0) {
            // ほかの画面で削除されたか、削除を 2 回押して 2 回届いた。どちらでも、もうないことだけを伝える
            return to_route('goals.index')->with('status', 'この目標は、もう削除されています');
        }

        return to_route('goals.index')->with('status', '削除しました');
    }

    /**
     * 押さえた口座が、目標に使えるかを確かめ直す（入力チェックのあと、保存までのあいだに、
     * ほかの画面で口座が「使わない」・クレジットカードになった・削除されたときに、そのまま保存しないように）。
     * 口座の管理も、同じ口座を押さえてから目標を確かめるので、どちらかが先に終わるまで待つ
     */
    private function checkAccount(?Account $locked, ?int $goalId): void
    {
        $problem = GoalRequest::accountProblem($locked, $goalId);
        if ($problem !== null) {
            throw ValidationException::withMessages(['account_id' => $problem]);
        }
    }

    /** 保存しようとした口座が、ほかの目標で使われていたとき。口座の欄のまちがいとして戻す */
    private function accountTaken(int $accountId, ?int $goalId): RedirectResponse
    {
        $problem = GoalRequest::accountProblem(Account::query()->find($accountId), $goalId)
            ?? 'この口座は、ほかの目標で使っているので選べません。1 つの口座は 1 つの目標だけに使えます';

        return back()->withInput()->withErrors(['account_id' => $problem]);
    }

    /**
     * 追加・変更の画面に渡すもの
     *
     * @return array{goal: Goal, accounts: Collection<int, Account>, years: list<int>}
     */
    private function formData(Goal $goal): array
    {
        // 選べる口座：「使う」口座で、クレジットカードではなく、ほかの目標で使っていないもの（この目標が使っている口座は選べる）
        $taken = Goal::query()->when($goal->exists, fn ($q) => $q->whereKeyNot($goal->id))->pluck('account_id');
        $accounts = Account::query()
            ->where('is_active', true)
            ->where('type', '!=', AccountType::CreditCard)
            ->whereNotIn('id', $taken)
            ->ordered()
            ->get();

        // 期限の年：今年から 40 年先まで。期限を過ぎた目標は、その年も選べるようにしておく（期限を変えずにほかの欄だけ直せるように）。
        // 年をまたいで開いたままの画面から保存してまちがいで戻ったときも、打った年が欄から黙って外れないように、その年も入れる
        $thisYear = CarbonImmutable::today()->year;
        $oldYear = old('deadline_year');
        $from = min($thisYear, $goal->deadline_month->year ?? $thisYear, is_numeric($oldYear) && (int) $oldYear >= 1900 ? (int) $oldYear : $thisYear);

        return [
            'goal' => $goal,
            'accounts' => $accounts,
            'years' => range($from, $thisYear + GoalRequest::YEARS_AHEAD),
        ];
    }
}
