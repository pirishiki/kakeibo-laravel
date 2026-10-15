// Bootstrap の動き（確認の小窓・「保存しました」の表示・月のまとめの開く／閉じるなど）を使えるようにする
// bootstrap を読みこむと、data-bs-toggle="collapse" のボタン（開く／閉じる）も動くようになる。2 回読みこむと、開いたまま閉じなくなるので注意
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

// 選ぶ欄が決まった値のときだけ隠すまとまり（例：口座の種類がクレジットカードなら、取っておく額を隠す。画面設計書 5.6）
// data-hide-when に欄の id、data-hide-value に隠すときの値を書く
document.querySelectorAll('[data-hide-when]').forEach((block) => {
    const field = document.getElementById(block.dataset.hideWhen);
    if (!field) {
        return;
    }
    const update = () => {
        block.hidden = field.value === block.dataset.hideValue;
    };
    field.addEventListener('change', update);
    update();
});

// 欄に打ったら、となりの選ぶ丸を選んだことにする（例：取っておく額の金額の欄に打つと「金額」。
// 「なし」のまま金額を打って保存し、打った金額が使われないことがないように）。data-select-radio に丸の id を書く
// 人が打ったときだけ（isTrusted）。欄に入る・出るときのカンマのつけ外しでも input の合図が出るので、それでは選び直さない
// （「全部取っておく」を選び直したあと、Tab で金額の欄を通っただけで「金額」に戻らないように）
document.addEventListener('input', (event) => {
    if (!event.isTrusted) {
        return;
    }
    const radio = event.target instanceof HTMLElement && event.target.dataset.selectRadio
        ? document.getElementById(event.target.dataset.selectRadio)
        : null;
    if (radio instanceof HTMLInputElement && event.target.value.trim() !== '') {
        radio.checked = true;
    }
});

// 「今回はなし」のあとに出し直したホームの URL の札（?draft=…）は、使い終わったので外す（読みこみ直したときに残らないように）
if (new URLSearchParams(window.location.search).has('draft')) {
    const url = new URL(window.location.href);
    url.searchParams.delete('draft');
    history.replaceState(null, '', url.pathname + url.search + url.hash);
}

// Livewire の部品から小窓を開く（定期的な記録の保存の前の確認など）。PHP で $this->dispatch('open-modal', id: '小窓の id') と書く
window.addEventListener('open-modal', (event) => {
    const dialog = document.getElementById(event.detail.id);
    if (dialog) {
        Modal.getOrCreateInstance(dialog).show();
    }
});

// パスワードを見せる・隠すボタン（ログイン。要件定義書 No.22）。data-password-toggle に欄の id を書く
// 欄の type を password ↔ text に入れかえるだけで、パスワードはどこにも送らない
document.querySelectorAll('[data-password-toggle]').forEach((button) => {
    const field = document.getElementById(button.dataset.passwordToggle);
    button.addEventListener('click', () => {
        const show = field.type === 'password';
        field.type = show ? 'text' : 'password';
        // 読み上げでは「パスワードを表示する、押されている／押されていない」と伝わる（ラベルは変えず、押されているかだけを切りかえる）
        button.setAttribute('aria-pressed', String(show));
        button.querySelector('.eye-open').hidden = show;
        button.querySelector('.eye-closed').hidden = !show;
    });
    // ログインを押して送るときは、隠した形に戻す（ブラウザのパスワードを覚える機能が、パスワードの欄だと気づけるように）
    field.form?.addEventListener('submit', () => {
        field.type = 'password';
    });
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

// ふつうのフォーム（口座・分類・相手の追加・変更・削除・まとめる、ログアウトなど。Livewire でないもの）の 2 回押しを止める。
// 2 回目が「その名前はもう使われています」や「見つかりません」になって、失敗したように見えないようにするため。
// 送るまえに確認の小窓を出すフォーム（相手をまとめる）は、先に送るのを止める（defaultPrevented）ので、そのときは何もしない。
// Livewire のフォーム（wire:submit）は、Livewire が送るのを止めて、ボタンを押せなくする（wire:loading.attr）
document.addEventListener('submit', (event) => {
    const form = event.target;
    if (event.defaultPrevented || !(form instanceof HTMLFormElement) || form.hasAttribute('wire:submit')) {
        return;
    }
    if (form.dataset.submitting) {
        event.preventDefault();
        return;
    }
    form.dataset.submitting = '1';
    // 送る中身が決まってから押せなくする（押したボタンを先に押せなくすると、そのボタンの値が送られなくなるため）
    setTimeout(() => {
        document.querySelectorAll('[type=submit]').forEach((button) => {
            if (button.form === form) {
                button.disabled = true;
            }
        });
    }, 0);
});
// ブラウザの「戻る」で、前の画面をそのまま出したとき（ブラウザがとっておいた画面）の扱い。
// とっておいた画面は、残高などが古いままで、送ったあとの押せないボタンもそのままなので、ふつうは読みこみ直して、いまの中身で出し直す。
// ただし、まだ送っていない打ちかけの入力があるときは、読みこみ直すと入力が消えるので、そのまま出して、上に知らせと［読みこみ直す］を出す。
// 打ったあとに送って保存できたときは、入力はもう届いているので、今までどおり読みこみ直す（古い画面から 2 回目を送らないように）。
// Livewire のフォームは、送っても画面に残ることがある（入力のまちがいがあったときなど）ので、保存して別の画面へ移ったとき（redirect）だけ数える
let typedSinceSubmit = false;
let submitted = false;
// 人が打った・選んだときだけ数える（カンマのつけ外しなど、プログラムが出す合図は数えない）
const markTyped = (event) => {
    if (!event.isTrusted) {
        return;
    }
    typedSinceSubmit = true;
    submitted = false;
};
const markSubmitted = () => {
    submitted = true;
    typedSinceSubmit = false;
};
document.addEventListener('input', markTyped);
document.addEventListener('change', markTyped);
document.addEventListener('submit', (event) => {
    // 確認の小窓を出すために止めた送信（相手をまとめる）は、まだ送っていない
    if (!event.defaultPrevented && !(event.target instanceof HTMLFormElement && event.target.hasAttribute('wire:submit'))) {
        markSubmitted();
    }
});
document.addEventListener('livewire:init', () => {
    window.Livewire.hook('effect', ({ effects }) => {
        if (effects?.redirect) {
            markSubmitted();
        }
    });
    // Livewire の画面（記録の登録など）で、時間がたって保存できなかったとき（419）。
    // Livewire は英語の小窓を出し、「OK」で読みこみ直して入力を消すので、それを止めて、入力を画面に残したまま日本語で知らせる（画面設計書 4 章の 12）
    window.Livewire.hook('request', ({ fail }) => {
        fail(({ status, preventDefault }) => {
            if (status === 419) {
                preventDefault();
                keepFieldsForReload();
                showPageAlert('画面を開いてから時間がたっていたので、操作できませんでした。［読みこみ直す］を押すと、入れた内容を欄に戻します（ログインが切れていたら、ログインし直したあとに戻します）。');
            }
        });
    });
});

// 保存できなかった画面の入力を、このタブのあいだだけブラウザに取っておき（sessionStorage）、同じ画面を開き直したときに欄へ戻す。
// パスワードの欄は取っておかない。1 日より古いものは戻さない
const KEPT_FIELDS = 'kakeibo-kept-fields';
function keepFieldsForReload() {
    const fields = [...document.querySelectorAll('main input[id], main select[id], main textarea[id]')]
        .filter((el) => !['password', 'hidden', 'file', 'submit', 'button'].includes(el.type))
        .map((el) => ({ id: el.id, value: el.value, checked: el.checked ?? false, type: el.type }));
    try {
        sessionStorage.setItem(KEPT_FIELDS, JSON.stringify({ path: location.pathname + location.search, at: Date.now(), fields }));
    } catch {
        // 取っておけないブラウザの設定のときは、知らせの文のとおり画面に残っている内容を使ってもらう
    }
}
document.addEventListener('livewire:initialized', () => {
    let kept = null;
    try {
        kept = JSON.parse(sessionStorage.getItem(KEPT_FIELDS) ?? 'null');
    } catch {
        kept = null;
    }
    if (!kept || kept.path !== location.pathname + location.search) {
        return;
    }
    try {
        sessionStorage.removeItem(KEPT_FIELDS);
    } catch {
        // 消せなくても、1 日たてば使わない
    }
    if (Date.now() - kept.at > 86400000) {
        return;
    }
    kept.fields.forEach(({ id, value, checked, type }) => {
        const el = document.getElementById(id);
        if (!el) {
            return;
        }
        if (type === 'checkbox' || type === 'radio') {
            el.checked = checked;
            el.dispatchEvent(new Event('change', { bubbles: true }));
        } else {
            el.value = value;
            // Livewire に新しい値を知らせる（wire:model は input の合図で値を受けとる）
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
        }
    });
    // 戻した内容は、まだ保存していない打ちかけの入力として数える（「戻る」で読みこみ直して消さないように）
    typedSinceSubmit = true;
    submitted = false;
    showPageAlert('前に入れた内容を、欄に戻しました。確かめてから、もう一度保存してください。', false);
});
window.addEventListener('pageshow', (event) => {
    if (!event.persisted) {
        return;
    }
    if (submitted || !typedSinceSubmit) {
        window.location.reload();
        return;
    }
    showPageAlert('前の画面を出しています。残高などが古いかもしれません');
});

// 画面の上に知らせを出す。withReload のときは［読みこみ直す］もつける。
// 知らせはいつも 1 つにして、もう出ているときは、新しい知らせに入れかえる（あとの知らせが黙って出ないことがないように）
function showPageAlert(message, withReload = true) {
    document.getElementById('page-alert')?.remove();
    const alert = document.createElement('div');
    alert.id = 'page-alert';
    alert.className = 'alert alert-warning d-flex justify-content-between align-items-center gap-2 flex-wrap';
    alert.setAttribute('role', 'alert');
    const text = document.createElement('span');
    text.textContent = message;
    alert.append(text);
    if (withReload) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-sm btn-outline-secondary';
        button.textContent = '読みこみ直す';
        button.addEventListener('click', () => window.location.reload());
        alert.append(button);
    }
    document.querySelector('main')?.prepend(alert);
    alert.scrollIntoView({ block: 'nearest' });
}
