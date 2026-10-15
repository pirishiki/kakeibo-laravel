<?php

namespace App\Http\Controllers;

use App\Enums\CategoryKind;
use App\Enums\RecordType;
use App\Models\Account;
use App\Models\Category;
use App\Models\Partner;
use App\Models\Record;
use App\Support\FormInput;
use App\Support\MonthlySummary;
use App\Support\RecordSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\MessageBag;
use Illuminate\View\View;

/**
 * 記録の一覧（画面設計書 5.3）。登録は Livewire の部品（app/Livewire/RecordForm.php）
 */
class RecordController extends Controller
{
    public function index(Request $request): View
    {
        $month = MonthlySummary::month($request->query('month'));

        // 「探す」の条件が URL にあるときは、月の切りかえのかわりに、条件に合う記録を全部の月から出す（要件定義書 No.18）
        $search = RecordSearch::requested($request) ? RecordSearch::fromRequest($request) : null;
        $searchErrors = $search instanceof MessageBag ? $search : new MessageBag;
        $search = $search instanceof RecordSearch ? $search : null;

        $cursor = self::cursor($request->query('before'));
        // 続きのページ・最初のページの URL。月ごとの一覧では、見ている月も URL に入れる
        // （月を書いていない /records のまま 0 時をこえて月が変わると、続きを新しい月から探して「続きはありません」になるため）
        $pageQuery = $search === null && $searchErrors->isEmpty() ? ['month' => $month->format('Y-m')] : [];

        // 条件にまちがいがあるときは、何も出さず、欄の知らせだけを出す
        $list = $searchErrors->isNotEmpty()
            ? ['records' => new Collection, 'count' => 0, 'income' => 0, 'expense' => 0, 'dayExpenses' => collect(),
                'start' => 1, 'next' => null, 'firstDayContinued' => false, 'lastDayContinues' => false]
            : $this->readList($search?->query() ?? MonthlySummary::records($month), $cursor);

        // 日付ごとのかたまりに分ける。並びは読んだときの順（新しい日が上）
        $days = $list['records']->groupBy(fn (Record $record) => $record->date->toDateString());

        return view('records.index', [
            'month' => $month,
            'search' => $search,
            'searchErrors' => $searchErrors,
            'searching' => RecordSearch::requested($request),
            // 「探す」の欄に入れておく値（URL の条件のまま。条件を変えるとき・まちがいを直すときに、打ち直さなくてよいように）
            'searchValues' => collect(RecordSearch::FIELDS)
                ->mapWithKeys(fn (string $key) => [$key => is_string($value = $request->query($key)) ? $value : ''])
                ->all(),
            'count' => $list['count'],
            // 合計：移動と調整は入れない（ホームの今月の収支・月のまとめと同じ数え方）。探しているときは、見つかった記録だけで計算する
            'income' => $list['income'],
            'expense' => $list['expense'],
            'days' => $days,
            'dayExpenses' => $list['dayExpenses'],
            // 何件目から出しているか（1 から数える）と、続きを開く URL（続きがないときは null）
            'start' => $list['start'],
            'nextUrl' => $list['next'] === null ? null : $request->fullUrlWithQuery([...$pageQuery, 'before' => $list['next']]),
            // 続きの印を読めたとき（続きのページを出しているとき）だけ。形のちがう印は読まずに 1 ページ目を出すので、「最初から見る」は出さない
            // 行から編集を開いたときの戻る先（今のページ。月ごとの一覧では見ている月も入れる。続きの URL と同じ理由）
            'backUrl' => $request->fullUrlWithQuery($pageQuery),
            'firstUrl' => $cursor === null ? null : $request->fullUrlWithQuery([...$pageQuery, 'before' => null]),
            'firstDayContinued' => $list['firstDayContinued'],
            'lastDayContinues' => $list['lastDayContinues'],
            ...$this->searchOptions(),
        ]);
    }

    /**
     * 一覧に 1 回で出す記録のいちばん多い数。これより多いときは、いちばん下の「続きを見る」で次を出す
     * （何年分もの記録を 1 ページに出すと、とても遅くなったり、エラーの画面になったりするため。要件定義書 N-12）
     */
    private const LIST_LIMIT = 500;

    /**
     * 続きの印（?before=2026-09-01.1234）を読む。「この日付・この番号の記録より前（古いほう）から出す」という意味。
     * 何ページ目かで続きを出すと、そのあいだにほかのタブで記録を消したときに、記録が 1 件飛ばされて見えなくなるため、印で出す。
     * 形がちがうときは null（はじめから出す）
     *
     * @return array{date: string, id: int}|null
     */
    private static function cursor(mixed $value): ?array
    {
        if (! is_string($value) || preg_match('/^(\d{4}-\d{2}-\d{2})\.(0|[1-9][0-9]{0,17})$/', $value, $m) !== 1 || ! FormInput::isDate($m[1])) {
            return null;
        }

        return ['date' => $m[1], 'id' => (int) $m[2]];
    }

    /**
     * 記録が続きの印より前（古いほう）か。並びは日付の新しい順・同じ日の中は番号の大きい順なので、日付が前か、同じ日で番号が小さいもの
     *
     * @param  Builder<Record>  $query
     * @param  array{date: string, id: int}  $cursor
     */
    private static function olderThan(Builder $query, array $cursor): void
    {
        $query->where(fn (Builder $q) => $q->where('date', '<', $cursor['date'])
            ->orWhere(fn (Builder $q) => $q->where('date', $cursor['date'])->where('id', '<', $cursor['id'])));
    }

    /**
     * 一覧に出す記録と、件数・合計・日付ごとの支出・続きの印。
     * 記録は、新しい日が上・同じ日の中はあとで入力したものが上。行に出す口座・分類（小分類のときは上の大分類も）・相手は、with() でまとめて読む。
     * 件数と合計は、ほかのページに出す記録もふくめて、データベースで同じ条件から数える。
     * 全部を 1 つのトランザクションの中で読む（同じ時点の記録で数えるので、読んでいるあいだに記録が増えても、一覧・件数・合計がずれない）
     *
     * @param  Builder<Record>  $query
     * @param  array{date: string, id: int}|null  $cursor
     * @return array{records: Collection<int, Record>, count: int, income: int, expense: int, dayExpenses: \Illuminate\Support\Collection<string, int>,
     *     start: int, next: string|null, firstDayContinued: bool, lastDayContinues: bool}
     */
    private function readList(Builder $query, ?array $cursor): array
    {
        return DB::transaction(function () use ($query, $cursor) {
            // 1 件多く読んで、続きがあるかを知る
            $page = (clone $query)
                ->when($cursor, fn (Builder $q, array $cursor) => self::olderThan($q, $cursor))
                ->with(['account', 'toAccount', 'category.parent', 'partner'])
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->limit(self::LIST_LIMIT + 1)
                ->get();
            $records = $page->take(self::LIST_LIMIT)->values();
            $following = $page->get(self::LIST_LIMIT);
            $last = $records->last();
            $first = $records->first();

            // 日付の見出しに出す、その日の支出の合計（収入・移動・調整は足さない。探しているときは、見つかった記録だけ）。
            // 日が前と次のページにまたがるときも、その日の分を全部足すため、データベースで数える
            $dayExpenses = (clone $query)->toBase()
                ->where('type', RecordType::Expense->value)
                ->whereIn('date', $records->map(fn (Record $record) => $record->date->toDateString())->unique()->values())
                ->groupBy('date')
                ->selectRaw('date, SUM(amount) AS total')
                ->get()
                ->mapWithKeys(fn ($row) => [substr((string) $row->date, 0, 10) => (int) $row->total]);

            // 続きのページで、いちばん上の日の記録が前のページにもあるか（「前のページから続く」と出すため）
            $firstDayContinued = $cursor !== null && $first !== null
                && (clone $query)->where('date', $first->date->toDateString())->where('id', '>', $first->id)->exists();

            return [
                'records' => $records,
                'count' => (clone $query)->count(),
                ...MonthlySummary::incomeExpenseOf($query),
                'dayExpenses' => $dayExpenses,
                // 何件目から出しているか：印より新しい記録の数 + 1
                'start' => $cursor === null ? 1 : (clone $query)->whereNot(fn (Builder $q) => self::olderThan($q, $cursor))->count() + 1,
                'next' => $following !== null && $last !== null ? $last->date->toDateString().'.'.$last->id : null,
                'firstDayContinued' => $firstDayContinued,
                'lastDayContinues' => $following !== null && $last !== null && $following->date->isSameDay($last->date),
            ];
        });
    }

    /**
     * 記録の編集（画面設計書 5.4）。入力欄と保存・削除は、登録と同じ Livewire の部品（RecordForm）
     */
    public function edit(Record $record): View
    {
        return view('records.edit', ['record' => $record]);
    }

    /**
     * 「探す」の欄で選べるもの。前の記録を探すため、「使わない」口座・分類・相手も選べるようにする（名前のうしろに「使わない」と出す）
     *
     * @return array<string, mixed>
     */
    private function searchOptions(): array
    {
        $accounts = Account::query()->ordered()->get();
        $parents = Category::query()->whereNull('parent_id')
            ->with(['children' => fn ($q) => $q->reorder()->orderByDesc('is_active')->orderBy('sort_order')])
            ->orderByDesc('is_active')
            ->orderBy('sort_order')
            ->get();

        return [
            'searchAccounts' => $accounts->sortByDesc('is_active')->values(),
            // 大分類は、支出・収入のまとまりに分けて、分類の管理と同じ順に並べる
            'searchParents' => [
                '支出' => $parents->where('kind', CategoryKind::Expense)->values(),
                '収入' => $parents->where('kind', CategoryKind::Income)->values(),
            ],
            'searchPartners' => Partner::listGroups(),
        ];
    }
}
