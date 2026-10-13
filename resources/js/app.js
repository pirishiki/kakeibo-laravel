// Bootstrap の動き（確認の小窓・「保存しました」の表示など）を使えるようにする
import { Toast } from 'bootstrap';

// 「保存しました」などのお知らせ（.toast）を出す。data-bs-delay の時間がたつと自動で消える
document.querySelectorAll('.toast').forEach((el) => Toast.getOrCreateInstance(el).show());
