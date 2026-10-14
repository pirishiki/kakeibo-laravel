// Bootstrap の動き（確認の小窓・「保存しました」の表示・月のまとめの開く／閉じるなど）を使えるようにする
// Collapse は読みこむだけで、data-bs-toggle="collapse" のボタンが動くようになる
import 'bootstrap/js/dist/collapse';
import { Modal, Toast } from 'bootstrap';
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

// 保存したあと、一覧の URL の後ろに #category-12 のような「しおり」がついていたら、その行を画面のまん中に出し、
// 数秒だけうすい青緑で光らせる（今保存した行がすぐわかるように。画面設計書 5.8）
const savedRow = window.location.hash ? document.getElementById(window.location.hash.slice(1)) : null;
if (savedRow?.classList.contains('list-group-item')) {
    savedRow.scrollIntoView({ block: 'center' });
    savedRow.classList.add('just-saved');
    setTimeout(() => savedRow.classList.remove('just-saved'), 3000);
    // しおりを URL から外す（読みこみ直したときに、また光らないように）
    history.replaceState(null, '', window.location.pathname + window.location.search);
}

// 名前をくらべるときの形をそろえる：全角と半角（ＡＢ／AB）、大文字と小文字を同じにする
// （データベースの名前のくらべ方〔データベース設計書 1 章〕でも、全角と半角の英数字は同じ名前になるため）
const normalizeName = (text) => text.normalize('NFKC').trim().toLowerCase();

// 名前で探す：打った文字をふくむ行だけを出す（相手の管理。画面設計書 5.9）
// data-filter に一覧の場所、一覧の中の data-filter-group にまとまり、行に data-name を書く
document.querySelectorAll('[data-filter]').forEach((input) => {
    const root = document.querySelector(input.dataset.filter);
    if (!root) {
        return;
    }
    const empty = root.querySelector('[data-filter-empty]');
    const update = () => {
        const word = normalizeName(input.value);
        let shown = 0;
        root.querySelectorAll('[data-filter-group]').forEach((group) => {
            let groupShown = 0;
            group.querySelectorAll('[data-name]').forEach((row) => {
                const hit = normalizeName(row.dataset.name).includes(word);
                row.classList.toggle('d-none', !hit);
                groupShown += hit ? 1 : 0;
            });
            group.hidden = groupShown === 0;
            shown += groupShown;
        });
        if (empty) {
            empty.hidden = shown > 0 || word === '';
        }
    };
    input.addEventListener('input', update);
});

// ほかの相手にまとめる（画面設計書 5.9）：まとめ先が候補にあるかを確かめてから、確認の小窓に名前を入れて出す。
// 小窓の「まとめる」を押したときだけ送る（欄で Enter を押しても、すぐには送らず、小窓を出す。まとめると元に戻せないため）
const mergeForm = document.getElementById('merge-form');
const mergeInput = document.getElementById('merge_target');
const mergeDialog = document.getElementById('merge-dialog');
if (mergeForm && mergeInput && mergeDialog) {
    const names = [...document.querySelectorAll(`#${mergeInput.getAttribute('list')} option`)].map((o) => o.value);
    let confirmed = false;

    const openDialog = () => {
        const target = names.find((name) => normalizeName(name) === normalizeName(mergeInput.value));
        mergeInput.classList.toggle('is-invalid', target === undefined);
        if (target === undefined) {
            mergeInput.focus();
            return;
        }
        mergeInput.value = target;
        mergeDialog.querySelector('[data-merge-target-name]').textContent = target;
        Modal.getOrCreateInstance(mergeDialog).show();
    };

    mergeForm.querySelector('[data-merge-open]')?.addEventListener('click', openDialog);
    mergeDialog.querySelector('[data-merge-confirm]')?.addEventListener('click', () => {
        confirmed = true;
    });
    mergeForm.addEventListener('submit', (event) => {
        if (!confirmed) {
            event.preventDefault();
            openDialog();
        }
    });
}
// 金額の欄（data-amount）：打ち終えて欄を離れたら、1,000,000 のようにカンマをつける。0 の打ちすぎに気づきやすくするため
// 打っているあいだはカンマをつけない（文字の位置がずれて打ちにくいため）。欄に戻ったらカンマを外す
// 全角の数字も半角にそろえる。サーバーでも、カンマと全角を読みかえてから確かめる（app/Support/FormInput.php）
// マイナス（調整・残高を合わせるの実際の金額）は、全角の「－」や、日本語入力のままの「ー」で打っても半角の「-」にそろえる
const toHalfWidth = (text) =>
    text.replace(/[０-９，]/g, (c) => String.fromCharCode(c.charCodeAt(0) - 0xfee0)).replace(/[－−ー‐]/g, '-');
const setAmount = (input, value) => {
    input.value = value;
    // Livewire に新しい値を知らせる（wire:model は、欄の input の合図で値を受けとる）
    input.dispatchEvent(new Event('input', { bubbles: true }));
};
document.addEventListener('focusout', (event) => {
    const input = event.target;
    if (!(input instanceof HTMLInputElement) || !input.matches('[data-amount]')) {
        return;
    }
    const digits = toHalfWidth(input.value).replace(/[,\s]/g, '');
    if (/^-?\d+$/.test(digits)) {
        setAmount(input, Number(digits).toLocaleString('ja-JP'));
    }
});
document.addEventListener('focusin', (event) => {
    const input = event.target;
    if (input instanceof HTMLInputElement && input.matches('[data-amount]') && input.value.includes(',')) {
        setAmount(input, input.value.replace(/,/g, ''));
    }
});
