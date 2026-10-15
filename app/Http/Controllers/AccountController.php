<?php

namespace App\Http\Controllers;

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
            Account::create($request->validated());
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
        ]);
    }

    public function update(AccountRequest $request, Account $account): RedirectResponse
    {
        $data = $request->validated();
        try {
            $blocker = DB::transaction(function () use ($account, $data) {
                // 「使わない」にするときは、口座を押さえてから確かめ直す（チェックのあと、保存するまでに記録ができても、残高のある口座を「使わない」にしないように）
                $locked = Account::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
                if ($locked->is_active && ! ($data['is_active'] ?? true)) {
                    $blocker = $locked->deactivationBlocker(isset($data['initial_balance']) ? (int) $data['initial_balance'] : null);
                    if ($blocker !== null) {
                        return $blocker;
                    }
                }
                $locked->update($data);

                return null;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            return $this->nameTaken();
        }
        if ($blocker !== null) {
            return back()->withInput()->withErrors(['is_active' => $blocker]);
        }

        return to_route('accounts.index')->with('status', '保存しました');
    }

    /** 削除。記録がある口座は削除できない（4 章の 9）。画面の小窓でも止めるが、ここでも確かめる */
    public function destroy(Account $account): RedirectResponse
    {
        if ($account->hasRecords()) {
            return to_route('accounts.edit', $account)->withErrors(['delete' => '記録があるので削除できません']);
        }
        if (Recurring::usingAccount($account->id)->exists()) {
            return to_route('accounts.edit', $account)->withErrors(['delete' => Recurring::DELETE_BLOCKED_MESSAGE]);
        }

        // 確かめてから削除するまでのあいだに記録ができたときも、データベースが止めるので、同じ文で戻す
        // （データベースが止めたことを受けとれるように、表に直接「消す」を送る）
        try {
            Account::query()->whereKey($account->id)->delete();
        } catch (QueryException) {
            return to_route('accounts.edit', $account)->withErrors(['delete' => '記録があるので削除できません']);
        }

        return to_route('accounts.index')->with('status', '削除しました');
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
