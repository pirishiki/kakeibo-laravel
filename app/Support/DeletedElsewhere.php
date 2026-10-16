<?php

namespace App\Support;

use App\Models\Account;
use App\Models\Category;
use App\Models\Goal;
use App\Models\Partner;
use App\Models\Record;
use App\Models\Recurring;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * 開いた・保存した口座や分類などが、ほかの画面で削除されていたとき（データベースに行がなく、Laravel が 404 にするとき）。
 * エラーの画面を出すかわりに、一覧へ戻して理由を知らせる。変更の画面から保存したときは、打った内容を捨てないように、
 * 追加の画面へ持っていく（貯金の目標と同じ。app/Http/Controllers/GoalController.php）。
 * bootstrap/app.php の例外の扱いから呼ぶ
 */
class DeletedElsewhere
{
    /**
     * 種類ごとの、文に出す名前と、ルートの名前の頭
     *
     * @var array<class-string<Model>, array{0: string, 1: string}>
     */
    private const KINDS = [
        Account::class => ['口座', 'accounts'],
        Category::class => ['分類', 'categories'],
        Partner::class => ['相手', 'partners'],
        Record::class => ['記録', 'records'],
        Recurring::class => ['定期的な記録', 'recurrings'],
        Goal::class => ['目標', 'goals'],
    ];

    /** 変更の画面から保存したとき、打った内容を追加の画面へ持っていく種類（記録・定期的な記録は Livewire の部品が自分で扱う） */
    private const KEEP_INPUT = ['accounts', 'categories', 'partners', 'goals'];

    /** 知らせて戻すときの答え。ほかの種類（決まっていない行）は null を返し、今までどおり 404 の画面にする */
    public static function response(ModelNotFoundException $e, Request $request): ?RedirectResponse
    {
        $kind = self::KINDS[$e->getModel()] ?? null;
        if ($kind === null) {
            return null;
        }
        [$label, $prefix] = $kind;

        // URL の番号の行（/accounts/3/edit の 3）がないのか、画面で選んだほかの行（まとめ先の相手など）がないのかを分ける
        if (! self::isRouteTarget($e, $request)) {
            if ($request->isMethod('GET')) {
                return to_route($prefix.'.index')->with('warning', 'この'.$label.'は、ほかの画面で削除されていました。');
            }

            return back()->withInput(self::input($request))
                ->with('warning', '選んだ'.$label.'は、ほかの画面で削除されていたので、保存していません。選び直してください。');
        }

        $deleted = 'この'.$label.'は、ほかの画面で削除されていました。';
        if ($request->isMethod('GET')) {
            return to_route($prefix.'.index')->with('warning', $deleted);
        }
        if ($request->isMethod('DELETE')) {
            // ほかの画面で削除されたか、削除を 2 回押して 2 回届いた。どちらでも、もうないことだけを伝える
            return to_route($prefix.'.index')->with('status', 'この'.$label.'は、もう削除されています');
        }
        if ($request->routeIs($prefix.'.update') && in_array($prefix, self::KEEP_INPUT, true)) {
            $create = self::createUrl($prefix, $request);
            if ($create !== null) {
                return redirect()->to($create)->withInput(self::input($request))
                    ->with('warning', $deleted.'保存していません。打った内容を入れておいたので、保存すると新しい'.$label.'として追加できます。');
            }
        }

        // 「使わない」にする・まとめる、など。もう行がないので、何もしていないことを伝える
        return to_route($prefix.'.index')->with('warning', $deleted.'何もしていません。');
    }

    /**
     * 見つからなかった行が、URL の番号の行か。
     * URL の番号で探せなかったときは、ルートに番号の文字のまま残る。読んだあとに消された（押さえて読み直したら、なかった）ときは、ルートに読んだ行が入っている
     */
    private static function isRouteTarget(ModelNotFoundException $e, Request $request): bool
    {
        $ids = array_map(strval(...), $e->getIds());
        foreach ($request->route()?->parameters() ?? [] as $value) {
            if (is_string($value) && in_array($value, $ids, true)) {
                return true;
            }
            // 押さえて読み直すときは findOrFail(番号) で探す（firstOrFail は番号を例外に入れないので、見分けられない）
            if ($value instanceof Model && $value::class === $e->getModel() && in_array((string) $value->getKey(), $ids, true)) {
                return true;
            }
        }

        return false;
    }

    /** 追加の画面の URL。小分類は、元の大分類がまだあるときだけ、その下に追加する画面へ（大分類も消えていたら、持っていく先がないので null） */
    private static function createUrl(string $prefix, Request $request): ?string
    {
        if ($prefix !== 'categories') {
            return route($prefix.'.create');
        }
        $parent = $request->input('parent_id');
        if ($parent === null || $parent === '') {
            return route('categories.create', ['kind' => $request->string('kind')->toString()]);
        }

        return is_numeric($parent) && Category::query()->whereNull('parent_id')->whereKey((int) $parent)->exists()
            ? route('categories.create', ['parent' => (int) $parent])
            : null;
    }

    /**
     * 欄に戻す、打った内容（合言葉や、古い画面を止める印は入れない）
     *
     * @return array<string, mixed>
     */
    private static function input(Request $request): array
    {
        return $request->except(['_token', '_method', 'opened_at', 'originals']);
    }
}
