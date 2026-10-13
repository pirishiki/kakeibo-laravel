<?php

namespace App\Http\Controllers;

use App\Http\Requests\AccountRequest;
use App\Models\Account;
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
        Account::create($request->validated());

        return to_route('accounts.index')->with('status', '保存しました');
    }

    public function edit(Account $account): View
    {
        return view('accounts.edit', [
            'account' => $account,
            'hasRecords' => $account->hasRecords(),
        ]);
    }

    public function update(AccountRequest $request, Account $account): RedirectResponse
    {
        $account->update($request->validated());

        return to_route('accounts.index')->with('status', '保存しました');
    }
}
