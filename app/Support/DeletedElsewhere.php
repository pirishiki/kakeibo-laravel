<?php

namespace App\Support;

use App\Http\Middleware\RestoreExpiredInput;
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
use Illuminate\Support\Arr;

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
        $keeps = in_array($prefix, self::KEEP_INPUT, true);
        if ($request->isMethod('GET')) {
            // 保存できずに打った内容を持って変更の画面へ戻ってきた（入力のまちがい・時間切れ・ログインし直し）ときは、それを捨てずに追加の画面へ持っていく
            $kept = $keeps && $request->routeIs($prefix.'.edit') ? self::keptInput($request) : null;
            if ($kept !== null) {
                return self::toCreate($prefix, $label, $kept, $deleted);
            }

            return to_route($prefix.'.index')->with('warning', $deleted);
        }
        if ($request->isMethod('DELETE')) {
            // ほかの画面で削除されたか、削除を 2 回押して 2 回届いた。どちらでも、もうないことだけを伝える
            return to_route($prefix.'.index')->with('status', 'この'.$label.'は、もう削除されています');
        }
        if ($request->routeIs('partners.merge')) {
            // まとめるを 2 回押して 2 回届いたときもここ（1 回目でまとめて、この相手はなくなっている）。失敗したと思わせないように
            return to_route('partners.index')->with('status', 'この相手は、もうまとめられているか、削除されています');
        }
        if ($request->routeIs($prefix.'.update') && $keeps) {
            return self::toCreate($prefix, $label, self::input($request), $deleted.'保存していません。');
        }

        // 「使わない」にする、など。もう行がないので、何もしていないことを伝える
        return to_route($prefix.'.index')->with('warning', $deleted.'何もしていません。');
    }

    /**
     * 打った内容を持って、追加の画面へ移る。持っていく先がないとき（小分類の元の大分類も削除されていた）は、一覧で打った名前を文に出す
     *
     * @param  array<string, mixed>  $input
     */
    private static function toCreate(string $prefix, string $label, array $input, string $message): RedirectResponse
    {
        $name = is_string($input['name'] ?? null) && $input['name'] !== '' ? $input['name'] : null;
        $create = self::createUrl($prefix, $input);
        if ($create === null) {
            return to_route($prefix.'.index')->with('warning', $message.($name !== null ? '打った名前は「'.$name.'」でした。' : ''));
        }
        // 追加の画面には「使う・使わない」の欄がない（追加したものは「使う」から始まる）ので、選んでいたことを黙って捨てずに伝える
        $inactive = ($input['is_active'] ?? null) === '0' ? '「使わない」は、追加したあとに変更の画面で選んでください。' : '';

        return redirect()->to($create)->withInput($input)
            ->with('warning', $message.'打った内容を入れておいたので、保存すると新しい'.$label.'として追加できます。'.$inactive);
    }

    /**
     * 変更の画面へ持って戻ってきた、打った内容（入力のまちがい・時間切れで戻ったときは old()、ログインし直したときは取っておいた入力）。ないときは null。
     * ログインし直したときの入力は、ふだんは RestoreExpiredInput が欄に戻すが、URL の番号で探せないとそこまで届かないので、ここで取り出す
     *
     * @return array<string, mixed>|null
     */
    private static function keptInput(Request $request): ?array
    {
        // 変更のフォームの内容（名前の欄がある）のときだけ持っていく。相手をまとめる欄だけのときなどは、追加の画面に入れるものがない
        $old = $request->session()->getOldInput();
        if (is_array($old) && array_key_exists('name', $old)) {
            return Arr::except($old, ['_token', '_method', 'opened_at', 'originals']);
        }
        $saved = $request->session()->get('expired_input');
        if (is_array($saved) && ($saved['at'] ?? 0) >= now()->timestamp - RestoreExpiredInput::KEEP_SECONDS
            && RestoreExpiredInput::place((string) ($saved['url'] ?? '')) === RestoreExpiredInput::place($request->fullUrl())) {
            $request->session()->forget('expired_input');
            $input = (array) ($saved['input'] ?? []);

            return array_key_exists('name', $input) ? Arr::except($input, ['_token', '_method', 'opened_at', 'originals']) : null;
        }

        return null;
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
    /** @param  array<string, mixed>  $input */
    private static function createUrl(string $prefix, array $input): ?string
    {
        if ($prefix !== 'categories') {
            return route($prefix.'.create');
        }
        $parent = $input['parent_id'] ?? null;
        if ($parent === null || $parent === '') {
            return route('categories.create', ['kind' => is_string($input['kind'] ?? null) ? $input['kind'] : '']);
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
