<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Http\Requests\AccountRequest;
use App\Models\Account;
use App\Models\Recurring;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * 口座の管理（画面設計書 5.6）：一覧・追加・変更
 */
class AccountController extends Controller
{
    public function index(): View
    {
        $accounts = Account::query()->ordered()->get();

        return view('accounts.index', [
            'active' => $accounts->where('is_active', true),
            'inactive' => $accounts->where('is_active', false),
            'balances' => Account::balances(),
        ]);
    }

    public function create(): View
    {
        return view('accounts.create', ['account' => new Account(['initial_balance' => 0])]);
    }

    public function store(AccountRequest $request): RedirectResponse
    {
        try {
            Account::create($request->accountData());
        } catch (UniqueConstraintViolationException) {
            return $this->nameTaken();
        }

        return to_route('accounts.index')->with('status', '保存しました');
    }

    public function edit(Account $account): View
    {
        return view('accounts.edit', [
            'account' => $account,
            'hasRecords' => $account->hasRecords(),
            // 削除を押したときの小窓で、「使わない」にできるかを先に伝えるため
            'blocker' => $account->is_active ? $account->deactivationBlocker() : null,
            // この口座を使っている定期的な記録（削除できない理由として、リンクつきで出す）
            'usedBy' => Recurring::usingAccount($account->id)->orderBy('day')->orderBy('id')->get(),
            // この口座を使っている貯金の目標（削除・「使わない」・クレジットカードにできない理由として、リンクつきで出す）
            'goal' => $account->goal(),
        ]);
    }

    public function update(AccountRequest $request, Account $account): RedirectResponse
    {
        $data = $request->accountData();
        try {
            $blocker = DB::transaction(function () use ($account, $data) {
                // 「使わない」にするときは、口座を押さえてから確かめ直す（チェックのあと、保存するまでに記録ができても、残高のある口座を「使わない」にしないように）
                $locked = Account::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
                // チェックのあと、押さえるまでに記録ができていたら、最初の残高は変えない（黙って書きかえないで知らせる）
                if (isset($data['initial_balance']) && (int) $data['initial_balance'] !== $locked->initial_balance && $locked->hasRecords()) {
                    return ['initial_balance', '開いたあとにこの口座の記録ができたので、最初の残高は変えられません。今の残高を直すときは「残高を合わせる」を使ってください。'];
                }
                // 貯金の目標が使っている口座は、クレジットカードに変えられない（残高がマイナスで貯金にならないため。画面設計書 5.6）
                if ($data['type'] === AccountType::CreditCard->value && $locked->type !== AccountType::CreditCard) {
                    $goal = $locked->goal();
                    if ($goal !== null) {
                        return ['type', $goal->blockedMessage('クレジットカードにできません')];
                    }
                }
                if ($locked->is_active && ! ($data['is_active'] ?? true)) {
                    $blocker = $locked->deactivationBlocker(isset($data['initial_balance']) ? (int) $data['initial_balance'] : null);
                    if ($blocker !== null) {
                        return ['is_active', $blocker];
                    }
                }
                $locked->update($data);

                return null;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            return $this->nameTaken();
        }
        if ($blocker !== null) {
            return back()->withInput()->withErrors([$blocker[0] => $blocker[1]]);
        }

        return to_route('accounts.index')->with('status', '保存しました');
    }

    /** 削除。記録がある口座は削除できない（4 章の 9）。画面の小窓でも止めるが、ここでも確かめる */
    public function destroy(Account $account): RedirectResponse
    {
        $blocked = $this->deleteBlocker($account);
        if ($blocked !== null) {
            return to_route('accounts.edit', $account)->withErrors(['delete' => $blocked]);
        }

        // 確かめてから削除するまでのあいだに記録・定期的な記録・目標ができたときも、データベースが止めるので、何が使っているかを確かめ直して戻す
        // （データベースが止めたことを受けとれるように、表に直接「消す」を送る）
        try {
            Account::query()->whereKey($account->id)->delete();
        } catch (QueryException $e) {
            // 23000 は、データベースの決まり（外部キーなど）で止まったとき。それ以外（つながらないなど）は、そのままエラーとして知らせる
            throw_if($e->getCode() !== '23000', $e);

            // 確かめ直すまでに、止めた理由（目標など）がなくなっていることもあるので、そのときは理由を決めつけずに知らせる
            return to_route('accounts.edit', $account)->withErrors(['delete' => $this->deleteBlocker($account)
                ?? 'ほかの画面でこの口座の使われ方が変わったので、削除していません。もう一度「削除」を押してください']);
        }

        return to_route('accounts.index')->with('status', '削除しました');
    }

    /** 口座を削除できない理由（記録 → 定期的な記録 → 貯金の目標の順に確かめる）。削除できるときは null */
    private function deleteBlocker(Account $account): ?string
    {
        if ($account->hasRecords()) {
            return '記録があるので削除できません';
        }
        if (Recurring::usingAccount($account->id)->exists()) {
            return Recurring::DELETE_BLOCKED_MESSAGE;
        }

        return $account->goal()?->blockedMessage('削除できません');
    }

    /** 残高を合わせる（画面設計書 5.7）。入力欄と保存は Livewire の部品（app/Livewire/AdjustBalance.php） */
    public function adjust(Account $account): View
    {
        return view('accounts.adjust', ['account' => $account]);
    }

    /** 削除の小窓から「使わない」にする（4 章の 9）。残高が 0 円の口座だけ */
    public function deactivate(Account $account): RedirectResponse
    {
        // 口座を押さえてから確かめて「使わない」にする（確かめたあと、変えるまでに記録ができても、残高のある口座を「使わない」にしないように）
        $blocker = DB::transaction(function () use ($account) {
            $locked = Account::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $blocker = $locked->deactivationBlocker();
            if ($blocker === null) {
                $locked->update(['is_active' => false]);
            }

            return $blocker;
        }, 3);

        if ($blocker !== null) {
            return to_route('accounts.edit', $account)->withErrors(['is_active' => $blocker]);
        }

        return to_route('accounts.index')->with('status', '「使わない」にしました');
    }

    /**
     * 入力チェックのあと、保存までのあいだに同じ名前の口座ができていたとき（保存を 2 回押したときなど）。
     * データベースの「同じ名前は 1 つだけ」の決まりで止まるので、名前のまちがいとして画面に戻す
     */
    private function nameTaken(): RedirectResponse
    {
        return back()->withInput()->withErrors(['name' => __('validation.unique', ['attribute' => __('validation.attributes.name')])]);
    }
}
