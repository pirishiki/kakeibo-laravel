<?php

namespace App\Http\Controllers;

use App\Http\Requests\PartnerRequest;
use App\Models\Partner;
use App\Models\Recurring;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * 相手の管理（画面設計書 5.9）：一覧・追加・変更・削除・「使わない」・ほかの相手にまとめる
 */
class PartnerController extends Controller
{
    public function index(): View
    {
        return view('partners.index', Partner::listGroups());
    }

    public function create(): View
    {
        return view('partners.create', ['partner' => new Partner]);
    }

    public function store(PartnerRequest $request): RedirectResponse
    {
        try {
            Partner::create($request->validated());
        } catch (UniqueConstraintViolationException) {
            return $this->nameTaken();
        }

        return to_route('partners.index')->with('status', '保存しました');
    }

    public function edit(Partner $partner): View
    {
        return view('partners.edit', [
            'partner' => $partner,
            'recordCount' => $partner->records()->count(),
            // この相手を使っている定期的な記録。削除・「使わない」にできない理由として、リンクつきで出す
            'usedBy' => Recurring::usingPartner($partner->id)->orderBy('day')->orderBy('id')->get(),
            'runningUsedBy' => Recurring::usingPartner($partner->id)->running()->orderBy('day')->orderBy('id')->get(),
            // まとめ先の候補：自分と「使わない」相手は選べない
            'mergeTargets' => Partner::query()->where('is_active', true)->whereKeyNot($partner->id)->orderBy('name')->pluck('name'),
        ]);
    }

    public function update(PartnerRequest $request, Partner $partner): RedirectResponse
    {
        try {
            $partner->update($request->validated());
        } catch (UniqueConstraintViolationException) {
            return $this->nameTaken();
        }

        return to_route('partners.index')->with('status', '保存しました');
    }

    /** 削除。記録がある相手は削除できない（4 章の 9） */
    public function destroy(Partner $partner): RedirectResponse
    {
        if ($partner->records()->exists()) {
            return to_route('partners.edit', $partner)->withErrors(['delete' => '記録があるので削除できません']);
        }
        if (Recurring::usingPartner($partner->id)->exists()) {
            return to_route('partners.edit', $partner)->withErrors(['delete' => Recurring::DELETE_BLOCKED_MESSAGE]);
        }

        // 確かめてから削除するまでのあいだに記録ができたときも、データベースが止めるので、同じ文で戻す
        // （データベースが止めたことを受けとれるように、表に直接「消す」を送る）
        try {
            Partner::query()->whereKey($partner->id)->delete();
        } catch (QueryException $e) {
            // 23000 は、データベースの決まり（外部キーなど）で止まったとき。それ以外（つながらないなど）は、そのままエラーとして知らせる
            throw_if($e->getCode() !== '23000', $e);

            return to_route('partners.edit', $partner)->withErrors(['delete' => '記録があるので削除できません']);
        }

        return to_route('partners.index')->with('status', '削除しました');
    }

    /** 削除の小窓から「使わない」にする（4 章の 9） */
    public function deactivate(Partner $partner): RedirectResponse
    {
        if (Recurring::usingPartner($partner->id)->running()->exists()) {
            return to_route('partners.edit', $partner)->withErrors(['is_active' => Recurring::IN_USE_MESSAGE]);
        }

        $partner->update(['is_active' => false]);

        return to_route('partners.index')->with('status', '「使わない」にしました');
    }

    /**
     * ほかの相手にまとめる（画面設計書 5.9）。まとめ先は名前で受けとる（打つと候補が出る欄のため）。
     * 自分と「使わない」相手は選べない
     */
    public function merge(Request $request, Partner $partner): RedirectResponse
    {
        if (is_string($request->input('merge_target'))) {
            $request->merge(['merge_target' => Partner::normalizeName($request->input('merge_target'))]);
        }

        $request->validate([
            'merge_target' => ['required', 'string', Rule::exists('partners', 'name')
                ->where('is_active', true)
                ->whereNot('id', $partner->id)],
        ]);

        $target = Partner::query()->where('name', $request->string('merge_target')->toString())->firstOrFail();

        // 確かめたあとに、まとめ先を「使わない」にした・記録が足された、などで、まとめられなかったときは、もう一度選んでもらう
        try {
            $merged = $partner->mergeInto($target);
        } catch (QueryException $e) {
            // 23000 は、データベースの決まり（外部キーなど）で止まったとき。それ以外（つながらないなど）は、そのままエラーとして知らせる
            throw_if($e->getCode() !== '23000', $e);
            $merged = false;
        }

        if (! $merged) {
            return back()->withInput()->withErrors(['merge_target' => 'まとめられませんでした。画面を読みこみ直してから、もう一度まとめてください']);
        }

        return to_route('partners.index')->with('status', 'まとめました');
    }

    /**
     * 入力チェックのあと、保存までのあいだに同じ名前の相手ができていたとき（保存を 2 回押したときなど）。
     * データベースの「同じ名前は 1 つだけ」の決まりで止まるので、名前のまちがいとして画面に戻す
     */
    private function nameTaken(): RedirectResponse
    {
        return back()->withInput()->withErrors(['name' => __('validation.unique', ['attribute' => __('validation.attributes.name')])]);
    }
}
