<?php

namespace App\Http\Controllers;

use App\Enums\CategoryKind;
use App\Http\Middleware\PreventsDoubleAdd;
use App\Http\Requests\CategoryRequest;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Recurring;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
        // 大分類がほかの画面で削除されていた・「使わない」になっていた。保存しようとして戻ってきたときは、打った名前を文に出す（欄に戻す先がないため）
        $name = old('name');
        $typed = is_string($name) && $name !== '' ? '打った名前「'.$name.'」は保存していません。' : '';
        if ($request->filled('parent') && $parent === null) {
            return to_route('categories.index')->with('warning', 'この大分類は、ほかの画面で削除されていました。'.$typed);
        }
        // 「使わない」大分類の下には、小分類を追加できない（画面設計書 5.8。2026-11-07 に決定）。
        // 選ぶ欄からも外すので、そのまま画面を出すと、ほかの大分類が黙って選ばれてしまう。一覧へ戻して知らせる
        if ($parent !== null && ! $parent->is_active) {
            return to_route('categories.index')->with('warning', $typed.Category::ADD_TO_INACTIVE_MESSAGE);
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

        try {
            $category = DB::transaction(function () use ($data) {
                if (isset($data['parent_id'])) {
                    // 親の大分類を押さえて読み直してから、「使う」かを確かめる（開いたあと、保存までに、ほかの画面で「使わない」に
                    // されていたら、「使わない」大分類の下に「使う」小分類を置かないため。画面設計書 5.8）。
                    // 大分類を「使わない」にする側も、同じ大分類の行を押さえてから小分類を数えるので、どちらかが先に終わるまで待つ
                    $parent = Category::query()->whereNull('parent_id')->lockForUpdate()->find($data['parent_id']);
                    if ($parent === null) {
                        throw ValidationException::withMessages(['parent_id' => self::PARENT_GONE_MESSAGE]);
                    }
                    if (! $parent->is_active) {
                        throw ValidationException::withMessages(['parent_id' => Category::ADD_TO_INACTIVE_MESSAGE]);
                    }
                    $data['kind'] = $parent->kind;
                }

                $category = Category::create($data);
                // 2 回目が届いたとき、1 回目で作った分類がまだあるかを確かめられるように覚えておく（app/Http/Middleware/PreventsDoubleAdd.php）
                PreventsDoubleAdd::remember($category);

                return $category;
            }, 3);
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

    public function edit(Category $category): View
    {
        $parent = $category->isParent() ? null : $category->parent;

        return view('categories.edit', [
            'category' => $category,
            // 親の選ぶ欄：「使う」大分類だけ。いまの親が「使わない」のときだけ、いまの親も「（使わない）」つきで出す（画面設計書 5.8）
            'parentOptions' => $category->isParent() ? collect() : $this->parentOptions($category->kind, $category->parent_id),
            // すでにデータにある「使わない」大分類の下の小分類（決まりができる前のもの）。勝手に変えず、知らせる
            'inactiveParent' => $parent !== null && ! $parent->is_active ? $parent : null,
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
                // 小分類を移すときは、移す先の大分類もいっしょに押さえる。分類は、どこでも id の小さい順に押さえる
                // （予算の保存・定期的な記録の保存と順番をそろえて、たがいを待ちつづけないように）
                // 小分類は、いまの親（送られてきた親が同じなら、それ）もいっしょに押さえる。「使う」に戻すときに、親が「使う」かを押さえて確かめるため
                $parentId = isset($data['parent_id']) ? (int) $data['parent_id'] : null;
                $rows = Category::query()->whereKey(array_filter([$category->id, $parentId]))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $locked = $rows->get($category->id) ?? throw (new ModelNotFoundException)->setModel(Category::class, [$category->id]);
                $locked->fill($data);

                // 入力のチェックのあと、押さえるまでに、ほかの画面で定期的な記録が変わっていることがあるので、押さえたあとに確かめ直す
                // （動いている予定が「使わない」分類を使う形にしないため。画面設計書 4 章の 9）
                $deactivating = $locked->isDirty('is_active') && ! $locked->is_active;
                if ($locked->isParent()) {
                    if ($deactivating) {
                        // 大分類を「使わない」にするときは、動いている予定と、「使う」小分類の両方を確かめる（どちらも直してもらうので、両方出す）
                        $problems = self::deactivateProblems($locked);
                        if ($problems !== []) {
                            throw ValidationException::withMessages(['is_active' => $problems]);
                        }
                    }
                } else {
                    if ($deactivating && Recurring::usingCategory($locked)->running()->exists()) {
                        throw ValidationException::withMessages(['is_active' => Recurring::IN_USE_MESSAGE]);
                    }
                    $parent = $rows->get($locked->parent_id);
                    if ($locked->isDirty('parent_id')) {
                        if ($parent === null) {
                            throw ValidationException::withMessages(['parent_id' => self::PARENT_GONE_MESSAGE]);
                        }
                        // 「使わない」大分類の下へは移せない（画面設計書 5.8。2026-11-07 に決定。押さえた親の、いちばん新しい中身で確かめる）
                        if (! $parent->is_active) {
                            throw ValidationException::withMessages(['parent_id' => Category::MOVE_TO_INACTIVE_MESSAGE]);
                        }
                    } elseif ($locked->isDirty('is_active') && $locked->is_active && $parent !== null && ! $parent->is_active) {
                        // 「使わない」大分類の下の小分類は、「使う」に戻せない。親も「使う」の欄も変えない保存（すでにデータにある古い形の、
                        // 名前だけの直しなど）は止めない（画面設計書 5.8）
                        throw ValidationException::withMessages(['is_active' => Category::RESTORE_UNDER_INACTIVE_MESSAGE]);
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
        // 押さえて読み直してから確かめて変える（ほかの画面で削除されていたら、「使わない」にしましたと出さずに知らせる。
        // 小窓を開いたあとに、ほかの画面で動いている予定がこの分類を使うようになった・「使う」小分類が足された、などのときは、「使わない」にしない）
        $problems = DB::transaction(function () use ($category) {
            $locked = Category::query()->lockForUpdate()->findOrFail($category->id);
            $problems = $locked->isParent()
                ? self::deactivateProblems($locked)
                : (Recurring::usingCategory($locked)->running()->exists() ? [Recurring::IN_USE_MESSAGE] : []);
            if ($problems === []) {
                $locked->update(['is_active' => false]);
            }

            return $problems;
        }, 3);
        if ($problems !== []) {
            return to_route('categories.edit', $category)->withErrors(['is_active' => $problems]);
        }

        return $this->toRow($category)->with('status', '「使わない」にしました');
    }

    /**
     * 大分類を「使わない」にできない理由（できるときは空）。大分類の行を押さえたあとに呼ぶ。
     * - 動いている定期的な記録が、この大分類（か、その下の小分類）を使っている（4 章の 9）
     * - この下に「使う」小分類がある（「使わない」大分類の下に「使う」小分類は置けない。画面設計書 5.8。2026-11-07 に決定）
     * 小分類の追加・移す・「使う」に戻すも、親の大分類の行を押さえてから確かめるので、押さえたあとに数えれば、
     * 数えたあとに「使う」小分類が足されることはない
     *
     * @return list<string>
     */
    private static function deactivateProblems(Category $locked): array
    {
        $problems = [];
        if (Recurring::usingCategory($locked)->running()->exists()) {
            $problems[] = Recurring::IN_USE_MESSAGE;
        }
        if ($locked->children()->where('is_active', true)->exists()) {
            $problems[] = Category::HAS_ACTIVE_CHILD_MESSAGE;
        }

        return $problems;
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
     * 小分類の親に選べる大分類（同じ用の「使う」大分類）。
     * 変更の画面で、いまの親が「使わない」のときだけ、いまの親も出す（選ぶ欄から黙って外れて、ほかの親が選ばれないように。画面設計書 5.8）
     *
     * @return Collection<int, Category>
     */
    private function parentOptions(CategoryKind $kind, ?int $currentParentId = null): Collection
    {
        return Category::query()->whereNull('parent_id')->where('kind', $kind)
            ->where(fn ($q) => $q->where('is_active', true)->when($currentParentId !== null, fn ($q) => $q->orWhere('id', $currentParentId)))
            ->orderBy('sort_order')->get();
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
