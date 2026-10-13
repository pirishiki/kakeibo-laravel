<?php

namespace App\Http\Controllers;

use App\Http\Requests\AccountRequest;
use App\Models\Account;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
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
        ]);
    }

    public function update(AccountRequest $request, Account $account): RedirectResponse
    {
        try {
            $account->update($request->validated());
        } catch (UniqueConstraintViolationException) {
            return $this->nameTaken();
        }

        return to_route('accounts.index')->with('status', '保存しました');
    }

    /** 削除。記録がある口座は削除できない（4 章の 9）。画面の小窓でも止めるが、ここでも確かめる */
    public function destroy(Account $account): RedirectResponse
    {
        if ($account->hasRecords()) {
            return to_route('accounts.edit', $account)->withErrors(['delete' => '記録があるので削除できません']);
        }

        $account->delete();

        return to_route('accounts.index')->with('status', '削除しました');
    }

    /** 削除の小窓から「使わない」にする（4 章の 9）。残高が 0 円の口座だけ */
    public function deactivate(Account $account): RedirectResponse
    {
        $blocker = $account->deactivationBlocker();

        if ($blocker !== null) {
            return to_route('accounts.edit', $account)->withErrors(['is_active' => $blocker]);
        }

        $account->update(['is_active' => false]);

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
