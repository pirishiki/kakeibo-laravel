<?php

namespace App\Http\Controllers;

use App\Enums\CategoryKind;
use App\Http\Requests\CategoryRequest;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Recurring;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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
    public function create(Request $request): View|RedirectResponse
    {
        $parent = $request->filled('parent')
            ? Category::query()->whereNull('parent_id')->find($request->integer('parent'))
            : null;
        if ($request->filled('parent') && $parent === null) {
            // 大分類がほかの画面で削除されていた。保存しようとして戻ってきたときは、打った名前を文に出す（欄に戻す先がないため）
            $name = old('name');

            return to_route('categories.index')->with('warning', 'この大分類は、ほかの画面で削除されていました。'
                .(is_string($name) && $name !== '' ? '打った名前「'.$name.'」は保存していません。' : ''));
        }

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
        } catch (QueryException $e) {
            // 確かめたあと、保存までに、親の大分類がほかの画面で削除された（データベースの外部キーで止まる）
            throw_if($e->getCode() !== '23000', $e);

            return back()->withInput()->withErrors(['parent_id' => self::PARENT_GONE_MESSAGE]);
        }

        return $this->toRow($category)->with('status', '保存しました');
    }

    /** 選んだ大分類が、保存までにほかの画面で削除されていたときの文 */
    private const PARENT_GONE_MESSAGE = '選んだ大分類は、ほかの画面で削除されていたので、保存していません。選び直してください';

    /** 小分類を「使わない」大分類の下へ移せないときの文（CategoryRequest と同じ） */
    private const MOVE_BLOCKED_MESSAGE = '「使う」の定期的な記録が使っているので、『使わない』の大分類の下へは移せません。先に定期的な記録を直してください';

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
            // 予算がある（過ぎた月の分もふくむ）大分類の id。小分類を移す元か移す先に予算があると、過去の月の予算の状況も変わるので、保存の前に知らせる
            'budgetedParentIds' => $category->isParent() ? [] : Budget::query()->whereIn('category_id', Category::query()->whereNull('parent_id')->select('id'))
                ->distinct()->pluck('category_id')->map(fn ($id) => (int) $id)->all(),
        ]);
    }

    public function update(CategoryRequest $request, Category $category): RedirectResponse
    {
        $data = $request->validated();

        try {
            $category = DB::transaction(function () use ($category, $data) {
                // 分類を押さえて読み直してから保存する（開いたあと、保存までにほかの画面で削除されていたら、
                // 何も保存していないのに「保存しました」と出さないように。なければ一覧へ戻して知らせる。app/Support/DeletedElsewhere.php）
                $locked = Category::query()->lockForUpdate()->findOrFail($category->id);
                $locked->fill($data);

                // 入力のチェックのあと、押さえるまでに、ほかの画面で定期的な記録が変わっていることがあるので、押さえたあとに確かめ直す
                // （動いている予定が「使わない」分類を使う形にしないため。画面設計書 4 章の 9）。押さえる順番は 小分類→大分類
                $running = fn () => Recurring::usingCategory($locked)->running()->exists();
                if ($locked->isDirty('is_active') && ! $locked->is_active && $running()) {
                    throw ValidationException::withMessages(['is_active' => Recurring::IN_USE_MESSAGE]);
                }
                if (! $locked->isParent() && $locked->isDirty('parent_id')) {
                    $parent = Category::query()->sharedLock()->find($locked->parent_id);
                    if ($parent === null) {
                        throw ValidationException::withMessages(['parent_id' => self::PARENT_GONE_MESSAGE]);
                    }
                    if (! $parent->is_active && $running()) {
                        throw ValidationException::withMessages(['parent_id' => self::MOVE_BLOCKED_MESSAGE]);
                    }
                }

                // 小分類を別の大分類へ移したときは、移した先のいちばん下に入れる。過去の記録も小分類についていくので、いっしょに移る
                // 「使う」に戻したときも、使う分類のいちばん下に入れる（好きな場所へは、つまみで動かす）
                if ($locked->isDirty('parent_id') || ($locked->isDirty('is_active') && $locked->is_active)) {
                    $locked->sort_order = (int) Category::query()->sameLevelAs($locked)->max('sort_order') + 1;
                }
                $locked->save();

                return $locked;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            return $this->nameTaken();
        } catch (QueryException $e) {
            // 押さえて確かめたあとでも、データベースの決まり（外部キー）で止まったときは、エラーの画面にせずに戻す
            throw_if($e->getCode() !== '23000', $e);

            return back()->withInput()->withErrors(['parent_id' => self::PARENT_GONE_MESSAGE]);
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

        // 押さえて読み直してから、もう一度確かめて変える（ほかの画面で削除されていたら、「使わない」にしましたと出さずに知らせる。
        // 確かめたあとに、ほかの画面で動いている予定がこの分類を使うようになっていたら、「使わない」にしない）
        $blocked = DB::transaction(function () use ($category) {
            $locked = Category::query()->lockForUpdate()->findOrFail($category->id);
            if (Recurring::usingCategory($locked)->running()->exists()) {
                return true;
            }
            $locked->update(['is_active' => false]);

            return false;
        }, 3);
        if ($blocked) {
            return to_route('categories.edit', $category)->withErrors(['is_active' => Recurring::IN_USE_MESSAGE]);
        }

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
