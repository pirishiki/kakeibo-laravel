<?php

namespace App\Http\Controllers;

use App\Enums\CategoryKind;
use App\Http\Requests\CategoryRequest;
use App\Models\Category;
use App\Models\Recurring;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * 分類の管理（画面設計書 5.8）：一覧・追加・変更・削除・並べかえ・「使わない」
 */
class CategoryController extends Controller
{
    public function index(): View
    {
        // 大分類を並び順に、その下に小分類を並び順に。支出用と収入用は別の四角に出す
        // 「使わない」分類は、その段のいちばん下に出す（大分類は小分類ごと。使う分類が上にそろうように）
        $parents = Category::query()
            ->whereNull('parent_id')
            ->with(['children' => fn ($q) => $q->reorder()->orderByDesc('is_active')->orderBy('sort_order')])
            ->orderByDesc('is_active')
            ->orderBy('sort_order')
            ->get()
            ->groupBy(fn (Category $category) => $category->kind->value);

        return view('categories.index', ['parents' => $parents]);
    }

    /** 大分類の追加は ?kind=expense、小分類の追加は ?parent=1 のように、どこに作るかを URL の後ろにつける */
    public function create(Request $request): View
    {
        $parent = $request->filled('parent')
            ? Category::query()->whereNull('parent_id')->findOrFail($request->integer('parent'))
            : null;

        $category = new Category([
            'kind' => $parent->kind ?? (CategoryKind::tryFrom($request->string('kind')->toString()) ?? CategoryKind::Expense),
            'parent_id' => $parent?->id,
        ]);

        return view('categories.create', [
            'category' => $category,
            'parentOptions' => $parent !== null ? $this->parentOptions($parent->kind) : collect(),
        ]);
    }

    public function store(CategoryRequest $request): RedirectResponse
    {
        $data = $request->validated();

        if (isset($data['parent_id'])) {
            $data['kind'] = Category::query()->findOrFail($data['parent_id'])->kind;
        }

        try {
            $category = Category::create($data);
        } catch (UniqueConstraintViolationException) {
            return $this->nameTaken();
        }

        return $this->toRow($category)->with('status', '保存しました');
    }

    public function edit(Category $category): View
    {
        return view('categories.edit', [
            'category' => $category,
            'parentOptions' => $category->isParent() ? collect() : $this->parentOptions($category->kind),
            'recordCount' => $category->records()->count(),
            'hasChildren' => $category->isParent() && $category->children()->exists(),
            // この分類（大分類なら、その下の小分類も）を使っている定期的な記録。削除・「使わない」にできない理由として、リンクつきで出す
            'usedBy' => Recurring::usingCategory($category)->orderBy('day')->orderBy('id')->get(),
            'runningUsedBy' => Recurring::usingCategory($category)->running()->orderBy('day')->orderBy('id')->get(),
        ]);
    }

    public function update(CategoryRequest $request, Category $category): RedirectResponse
    {
        $category->fill($request->validated());

        // 小分類を別の大分類へ移したときは、移した先のいちばん下に入れる。過去の記録も小分類についていくので、いっしょに移る
        // 「使う」に戻したときも、使う分類のいちばん下に入れる（好きな場所へは、つまみで動かす）
        if ($category->isDirty('parent_id') || ($category->isDirty('is_active') && $category->is_active)) {
            $category->sort_order = (int) Category::query()->sameLevelAs($category)->max('sort_order') + 1;
        }

        try {
            $category->save();
        } catch (UniqueConstraintViolationException) {
            return $this->nameTaken();
        }

        return $this->toRow($category)->with('status', '保存しました');
    }

    /** 削除。小分類がある大分類と、記録がある分類は削除できない（4 章の 9・データベース設計書 3.3） */
    public function destroy(Category $category): RedirectResponse
    {
        if ($category->isParent() && $category->children()->exists()) {
            return to_route('categories.edit', $category)->withErrors(['delete' => '先に小分類を削除するか、別の大分類へ移してください']);
        }

        if ($category->records()->exists()) {
            return to_route('categories.edit', $category)->withErrors(['delete' => '記録があるので削除できません']);
        }
        if (Recurring::usingCategory($category)->exists()) {
            return to_route('categories.edit', $category)->withErrors(['delete' => Recurring::DELETE_BLOCKED_MESSAGE]);
        }

        // 確かめてから削除するまでのあいだに記録ができたときも、データベースが止めるので、同じ文で戻す
        // （データベースが止めたことを受けとれるように、表に直接「消す」を送る）
        try {
            Category::query()->whereKey($category->id)->delete();
        } catch (QueryException $e) {
            // 23000 は、データベースの決まり（外部キーなど）で止まったとき。それ以外（つながらないなど）は、そのままエラーとして知らせる
            throw_if($e->getCode() !== '23000', $e);

            return to_route('categories.edit', $category)->withErrors(['delete' => '記録があるので削除できません']);
        }

        return to_route('categories.index')->with('status', '削除しました');
    }

    /** 削除の小窓から「使わない」にする（4 章の 9） */
    public function deactivate(Category $category): RedirectResponse
    {
        if (Recurring::usingCategory($category)->running()->exists()) {
            return to_route('categories.edit', $category)->withErrors(['is_active' => Recurring::IN_USE_MESSAGE]);
        }

        $category->update(['is_active' => false]);

        return $this->toRow($category)->with('status', '「使わない」にしました');
    }

    /**
     * つまみのドラッグで並べかえたあと、画面から新しい順番（同じ段の分類の id を上から順に）を受けとって保存する。
     * 画面は読みこみ直さないので、答えは JSON で返す（resources/js/app.js）
     */
    public function reorder(Request $request): JsonResponse
    {
        $ids = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ])['ids'];

        if (! Category::saveOrder(array_values($ids))) {
            return response()->json(['message' => '順番を保存できませんでした。画面を読みこみ直してから、もう一度動かしてください'], 422);
        }

        return response()->json(['message' => '順番を保存しました']);
    }

    /** 一覧の、その分類の行へ戻る（分類が多く、いちばん上に戻ると探しにくいため） */
    private function toRow(Category $category): RedirectResponse
    {
        return redirect()->to(route('categories.index').'#category-'.$category->id);
    }

    /**
     * 小分類の親に選べる大分類（同じ用の大分類）
     *
     * @return Collection<int, Category>
     */
    private function parentOptions(CategoryKind $kind): Collection
    {
        return Category::query()->whereNull('parent_id')->where('kind', $kind)->orderBy('sort_order')->get();
    }

    /**
     * 入力チェックのあと、保存までのあいだに同じ名前の分類ができていたとき（保存を 2 回押したときなど）。
     * データベースの「同じ段で同じ名前は 1 つだけ」の決まりで止まるので、名前のまちがいとして画面に戻す
     */
    private function nameTaken(): RedirectResponse
    {
        return back()->withInput()->withErrors(['name' => __('validation.unique', ['attribute' => __('validation.attributes.name')])]);
    }
}
