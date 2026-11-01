<?php

namespace App\Http\Middleware;

use App\Support\SubmitOnce;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 口座・分類・相手の追加で、保存が 2 回届いても 1 件だけ作る（画面設計書 5.6。分類 5.8・相手 5.9 も同じ）。
 *
 * 追加の画面には、開くたびに「送信用の合言葉」（submit_token）を入れてある（SubmitOnce）。
 * 2 回目が同じ合言葉・同じ中身で届いたら、名前が重なったというエラーにせず、1 回目と同じ「保存しました」の流れで戻る
 * （保存できているのに失敗に見えないように）。別の内容なら、保存していないと知らせる。
 * 1 回目がまだ保存中のときは、少し待ってもらう。そのとき描き直す画面は同じ合言葉を引きつぐ
 * （新しい合言葉にすると、1 回目が保存できたあとに押したとき、別の保存と見なされて、また「もう使われています」と出てしまうため）。
 * 合言葉がない（この仕組みを入れる前に開いたままの画面）ときは、今までどおり動く
 */
class PreventsDoubleAdd
{
    /** 保存済みの画面で、別の内容を保存しようとしたとき（追加の画面は描き直すと新しい合言葉になるので、もう一度押せば新しく追加できる） */
    public const SAVED_MESSAGE = 'この画面からは、もう保存しています。入れた内容を新しく追加するときは、もう一度「保存」を押してください';

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->input('submit_token');
        if (! is_string($token) || $token === '') {
            return $next($request);
        }

        // 中身の印は、画面の部品の値だけで作る（フォームを送った画面の印や、CSRF の合言葉は、送るたびにちがうことがあるため入れない）
        $hash = SubmitOnce::hash($request->except(['_token', '_method', '_from', 'submit_token']));
        $claim = SubmitOnce::claim($token, $hash);

        if ($claim === SubmitOnce::SAME) {
            // 1 回目と同じ場所へ、同じ知らせで戻る。戻り先がわからないとき（合言葉の行が消えた直後など）は、送った画面へ
            $to = SubmitOnce::state($token)['to'] ?? null;

            return redirect()->to(is_string($to) && $to !== '' ? $to : (UseFormOrigin::origin($request) ?? route('home')))
                ->with('status', '保存しました');
        }
        if ($claim !== SubmitOnce::NEW) {
            $saving = $claim === SubmitOnce::SAVING;

            // 戻り先は、フォームを送った画面。わからないときは、ほかのタブで最後に開いた画面に入力を戻してしまわないように、ホームで知らせる
            $back = UseFormOrigin::origin($request);
            $input = $request->except(['_token', '_method', '_from', ...($saving ? [] : ['submit_token'])]);

            // 保存中のときは、同じ合言葉を引きついで描き直す（上の説明）。保存済みで別の内容のときは引きつがない（ずっと「保存済み」になるため）
            return $back === null
                ? redirect()->route('home')->with('warning', $saving ? SubmitOnce::SAVING_MESSAGE : self::SAVED_MESSAGE)
                : redirect()->to($back)->withInput($input)->with('warning', $saving ? SubmitOnce::SAVING_MESSAGE : self::SAVED_MESSAGE);
        }

        // ルートのミドルウェアでは、保存の途中の例外は「描かれた答え」として返ってくる（Laravel が例外を答えに変えてから渡すため）。
        // なので try/catch ではなく、返ってきた答えが「保存できた」ものかで、使い切るか戻すかを決める
        $response = $next($request);

        // 保存できたときだけ使い切る：「保存しました」の知らせつきの移動で、まちがいの知らせがない。
        // 見るのは、このリクエストで入れた知らせ（_flash.new）だけにする。前のリクエストの知らせ（別のタブで保存した直後の
        // 「保存しました」など）が残っていると、保存できなかったのに「保存できた」と見なしてしまうため。
        // 入力のまちがい・名前が重なった・押さえあい・親の分類が消えていた、などで保存できなかったときは、合言葉を戻して、
        // 直したあとにもう一度押せば保存できるようにする
        $flashed = (array) $request->session()->get('_flash.new', []);
        if ($response->isRedirection() && in_array('status', $flashed, true) && ! in_array('errors', $flashed, true)) {
            SubmitOnce::saved($token, $hash, ['to' => $response->headers->get('Location')]);
        } else {
            SubmitOnce::release($token);
        }

        return $response;
    }
}
