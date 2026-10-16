<?php

namespace App\Http\Controllers;

use App\Enums\CategoryKind;
use App\Models\Account;
use App\Models\Category;
use App\Models\Goal;
use App\Models\Partner;
use App\Models\Recurring;
use App\Support\Budgets;
use Carbon\CarbonImmutable;
use Illuminate\View\View;

/**
 * 設定（画面設計書 5.12）。たまにしか使わない画面へのリンクを並べる。
 * 各行の 2 行目に出す件数は、「使う」ものだけをデータベースで数える（行は読みこまず、件数だけを受けとる）
 */
class SettingsController extends Controller
{
    public function index(): View
    {
        // 「使わない」大分類の下の小分類は、記録の登録で選べないので数えない（大分類を「使わない」にしても、小分類は「使う」のまま残るため）
        $activeCategories = Category::query()->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('parent_id')
                ->orWhereHas('parent', fn ($parent) => $parent->where('is_active', true)));

        return view('settings.index', [
            'accountCount' => Account::query()->where('is_active', true)->count(),
            // 分類は大分類と小分類を合わせて、支出用・収入用に分けて数える
            'expenseCategoryCount' => (clone $activeCategories)->where('kind', CategoryKind::Expense)->count(),
            'incomeCategoryCount' => (clone $activeCategories)->where('kind', CategoryKind::Income)->count(),
            'partnerCount' => Partner::query()->where('is_active', true)->count(),
            // 定期的な記録は、「使う」でまだ終わっていないものだけ数える
            'recurringCount' => Recurring::query()->running()->count(),
            // 予算は、今月に使われる予算がある分類の数（月のまとめ・お知らせと同じく、「使わない」分類の予算は数えない）
            'budgetCount' => count(Budgets::amountsFor(CarbonImmutable::today(), CarbonImmutable::today())),
            // 貯金の目標は、達成したものもふくめて全部数える（一覧に出る数と同じ。目標には「使わない」がない）
            'goalCount' => Goal::query()->count(),
        ]);
    }
}
