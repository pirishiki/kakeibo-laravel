// Bootstrap の動き（確認の小窓・「保存しました」の表示など）を使えるようにする
import { Toast } from 'bootstrap';
// ドラッグで並べかえる部品（分類の管理。画面設計書 5.8）
import Sortable from 'sortablejs';

// 「保存しました」などのお知らせ（.toast）を出す。data-bs-delay の時間がたつと自動で消える
document.querySelectorAll('.toast').forEach((el) => Toast.getOrCreateInstance(el).show());

// 選ぶ欄を、もとの値から変えたときだけ出す文（例：小分類の親を選び直すと「記録 ○ 件もいっしょに移ります」。画面設計書 5.8）
// data-show-when-changed に欄の id、data-original にもとの値を書く
document.querySelectorAll('[data-show-when-changed]').forEach((note) => {
    const field = document.getElementById(note.dataset.showWhenChanged);
    const update = () => {
        note.hidden = field.value === note.dataset.original;
    };
    field.addEventListener('change', update);
    update();
});

// つまみ（.sort-handle）を押さえたまま動かすと並べかえられる一覧。data-sortable に、並べた順番の送り先を書く
// 動かせるのは、その一覧のすぐ下の data-id のついたものだけ（大分類どうし・同じ大分類の中の小分類どうし）
// data-fixed のついたもの（「使わない」分類）は、いつも下に置くので、動かせず、そのあいだや下にも置けない
const sortables = [];

const saveFailedMessage = (response, body) => {
    if (response?.status === 401 || response?.status === 419) {
        return 'ログインが切れました。画面を読みこみ直します';
    }

    return response?.status === 422 && body?.message ? body.message : '順番を保存できませんでした。画面を読みこみ直してから、もう一度動かしてください';
};

document.querySelectorAll('[data-sortable]').forEach((list) => {
    const sortable = Sortable.create(list, {
        handle: '.sort-handle',
        draggable: '[data-id]:not([data-fixed])',
        onMove: (event) => !event.related.hasAttribute('data-fixed'),
        animation: 150,
        // 大分類のまとまりを、小分類の行の上を通って動かしやすくする
        fallbackOnBody: true,
        swapThreshold: 0.65,
        onEnd: async (event) => {
            if (event.oldIndex === event.newIndex) {
                return;
            }

            // 保存が終わるまで、次のドラッグを止める（続けて動かすと、送った順番が前後して古い順番が残ることがあるため）
            sortables.forEach((s) => s.option('disabled', true));

            const ids = [...list.children].filter((el) => el.dataset.id).map((el) => Number(el.dataset.id));
            const response = await fetch(list.dataset.sortable, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify({ ids }),
            }).catch(() => null);

            // 保存できなかったときは、知らせてから読みこみ直し、画面の順番をデータベースの順番にもどす
            if (!response?.ok) {
                const body = await response?.json().catch(() => null);
                window.alert(saveFailedMessage(response, body));
                window.location.reload();
                return;
            }

            sortables.forEach((s) => s.option('disabled', false));
        },
    });
    sortables.push(sortable);
});
