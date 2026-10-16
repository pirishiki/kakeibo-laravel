<?php

namespace App\Http\Controllers;

use App\Enums\CategoryKind;
use App\Models\Budget;
use App\Models\Category;
use App\Support\Budgets;
use App\Support\FormInput;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * 予算を決める（画面設計書 5.11）。/budgets はふだんの予算、/budgets?month=2026-12 は特別な月。
 * 支出の分類（「使う」ものだけ）を分類の管理と同じ順に並べ、金額の欄をまとめて保存する
 */
class BudgetController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $today = CarbonImmutable::today();
        $month = $today->startOfMonth();
        $special = null;
        if ($request->has('month')) {
            $special = self::specialMonth($request->query('month'), $today);
            // 形は合っているが、もう直せない（または 12 か月より先の）月：開いたまま月が変わった画面の URL や、
            // 時間切れ（419）・ログインし直しで戻ってきたときなど。黙って別の月を出すと、戻した入力が別の月に入ってしまうので、
            // 保存のときと同じく、今月の特別な月へ移って知らせる（移ると、戻した入力も 1 回で消える）
            if ($special === null && self::isMonthText($request->query('month'))) {
                return to_route('budgets.index', ['month' => $month->format('Y-m')])
                    ->with('warning', self::PASSED_MONTH_MESSAGE);
            }
            // 形がちがうとき（?month= だけなど）は来月
            $special ??= $month->addMonth();
        }

        // 分類の管理と同じ順。「使わない」分類と、「使わない」大分類の下の小分類は出さない
        $parents = Category::query()
            ->where('kind', CategoryKind::Expense)->whereNull('parent_id')->where('is_active', true)
            ->with(['children' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('sort_order')
            ->get();

        $usual = Budget::query()->usualFor($special ?? $month)->pluck('amount', 'category_id');
        $values = $special !== null ? Budget::query()->specialFor($special)->pluck('amount', 'category_id') : $usual;
        // 小分類の合計がこえているかは、その月に使う予算で数える（特別な月で空の欄は、ふだんの予算）
        $effective = $special !== null ? $values->union($usual) : $values;

        return view('budgets.index', [
            'parents' => $parents,
            'special' => $special,
            'first' => $month,
            // 時間切れ（419）・ログインし直しで戻した入力は、同じ画面（ふだんの予算／同じ特別な月）のものだけを欄に戻す
            'useOld' => old('month') === $special?->format('Y-m'),
            'last' => $month->addMonths(Budgets::SPECIAL_MONTHS_AHEAD),
            // ふだんの予算：特別な月のときは、分類の横に小さく出す（その月に使われるふだんの予算）
            'usual' => $usual,
            // 欄に入れておく金額
            'values' => $values,
            'over' => self::overParents($parents, $effective->map(fn ($amount) => (int) $amount)->all()),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $today = CarbonImmutable::today();
        $special = null;
        if ($request->filled('month')) {
            $special = self::specialMonth($request->input('month'), $today);
            if ($special === null) {
                // 開いたまま月が変わって、選んでいた月が過ぎた（または 12 か月先をこえた）。過ぎた月の予算は直さない（画面設計書 5.11）
                return to_route('budgets.index', ['month' => $today->startOfMonth()->format('Y-m')])
                    ->with('warning', self::PASSED_MONTH_MESSAGE);
            }
        }

        [$amounts, $originals] = $this->validated($request);
        $result = Budgets::save($special, $amounts, $originals, $today);
        $savedAny = $result['saved'] > 0;

        $back = to_route('budgets.index', $special !== null ? ['month' => $special->format('Y-m')] : []);
        $messages = [];
        if ($result['conflicts'] !== []) {
            $messages[] = '「'.implode('」「', $result['conflicts']).'」は、開いたあとにほかの画面で予算が変わっていたので、保存していません。今の予算を出したので、確かめてからもう一度保存してください。';
            // 打った金額は欄に残す（今の予算は「開いたときの金額」として持ちなおすので、次はそのまま保存できる）。どの画面の入力かも持っていく
            $back->withInput([
                'amounts' => array_intersect_key((array) $request->input('amounts', []), $result['conflicts']),
                'month' => $special?->format('Y-m'),
            ]);
        }
        if ($result['unusable'] !== []) {
            $messages[] = '「'.implode('」「', $result['unusable']).'」は、開いたあとに『使わない』になったので、保存していません。';
        }
        if ($result['parentUnusable'] !== []) {
            $messages[] = '「'.implode('」「', $result['parentUnusable']).'」は、開いたあとに大分類が『使わない』になったので、保存していません。';
        }
        if ($result['missing'] > 0) {
            $messages[] = '開いたあとに削除された分類が '.$result['missing'].' つあったので、その分は保存していません。';
        }
        // ふだんの予算を、月末に開いて月初めに保存したとき：「今月から」は保存したときの月になるので、黙らずに知らせる
        $opened = self::isMonthText($request->input('opened')) ? CarbonImmutable::createFromFormat('!Y-m', (string) $request->input('opened')) : null;
        if ($special === null && $savedAny && $opened !== null && ! $opened->equalTo($today->startOfMonth())) {
            $messages[] = $today->format('n月').'から新しい予算にしました（'.$opened->format('n月').'は前の予算のままです）。';
        }

        if ($messages === []) {
            return $back->with('status', '保存しました');
        }

        $hasProblem = $result['conflicts'] !== [] || $result['unusable'] !== [] || $result['parentUnusable'] !== [] || $result['missing'] > 0;

        return $back->with('warning', ($savedAny && $hasProblem ? 'ほかの分類は保存しました。' : '').implode(' ', $messages));
    }

    /** もう直せない月の特別な月を、保存しようとした・開こうとしたときの知らせ */
    private const PASSED_MONTH_MESSAGE = '選んでいた月は、もう直せない月になったので、保存していません。今月の分を出したので、もう一度入れてください。';

    /** 「2026-12」の形か */
    private static function isMonthText(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) === 1;
    }

    /**
     * 金額の欄をそろえてチェックする。空なら「予算なし」（特別な月では「ふだんのまま」）、入れるときは 1 円以上の整数
     *
     * @return array{0: array<int, string>, 1: array<int, string>} 打った金額と、開いたときの金額（分類の id → 数だけの形）
     */
    private function validated(Request $request): array
    {
        $amounts = [];
        $originals = [];
        $errors = [];
        foreach ((array) $request->input('amounts', []) as $id => $text) {
            if (! is_int($id) && ! ctype_digit((string) $id)) {
                continue;
            }
            $id = (int) $id;
            $value = FormInput::normalizeAmount(is_string($text) ? $text : '');
            if ($value !== '' && (preg_match('/^\d+$/', $value) !== 1 || (int) $value < 1 || strlen($value) > 9 || (int) $value > FormInput::MAX_AMOUNT)) {
                $errors['amounts.'.$id] = preg_match('/^-?\d+$/', $value) === 1
                    ? '1 円以上、'.number_format(FormInput::MAX_AMOUNT).' 円までの整数で入れてください'
                    : '数字だけで入れてください';
            }
            $amounts[$id] = $value;
            $original = $request->input('originals.'.$id);
            $originals[$id] = FormInput::normalizeAmount(is_string($original) ? $original : '');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [$amounts, $originals];
    }

    /**
     * ?month=2026-12 から特別な月を出す。今月から 12 か月先までだけ選べる。
     * 形がちがう・範囲の外のときは null（開くときは、形がちがえば来月、範囲の外なら今月へ移って知らせる。保存のときは保存しない）
     */
    private static function specialMonth(mixed $value, CarbonImmutable $today): ?CarbonImmutable
    {
        $first = $today->startOfMonth();
        $month = is_string($value) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) === 1
            ? CarbonImmutable::createFromFormat('!Y-m', $value)
            : null;

        if ($month === null || $month->lessThan($first) || $month->greaterThan($first->addMonths(Budgets::SPECIAL_MONTHS_AHEAD))) {
            return null;
        }

        return $month;
    }

    /**
     * 小分類の予算の合計が大分類の予算をこえている大分類（注意を出す。保存はできる）。
     * 特別な月では、空の欄はふだんの予算で数える
     *
     * @param  Collection<int, Category>  $parents
     * @param  array<int, int|null>  $effective  分類の id → その月に使う予算（なしは null）
     * @return array<int, array{children: int, parent: int}>
     */
    public static function overParents(Collection $parents, array $effective): array
    {
        $over = [];
        foreach ($parents as $parent) {
            $parentAmount = $effective[$parent->id] ?? null;
            $sum = $parent->children->sum(fn (Category $child) => $effective[$child->id] ?? 0);
            if ($parentAmount !== null && $sum > $parentAmount) {
                $over[$parent->id] = ['children' => (int) $sum, 'parent' => $parentAmount];
            }
        }

        return $over;
    }
}
