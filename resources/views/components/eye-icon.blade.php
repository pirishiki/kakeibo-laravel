{{-- 目のマーク（パスワードを見せる・隠すボタン）。隠しているときは目、見せているときは斜線つきの目を出す（resources/js/app.js が切りかえる） --}}
<svg class="eye-open" width="18" height="18" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true">
    <path d="M1 8s2.5-5 7-5 7 5 7 5-2.5 5-7 5-7-5-7-5z" />
    <circle cx="8" cy="8" r="2.2" />
</svg>
<svg class="eye-closed" width="18" height="18" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true" hidden>
    <path d="M1 8s2.5-5 7-5 7 5 7 5-2.5 5-7 5-7-5-7-5z" />
    <circle cx="8" cy="8" r="2.2" />
    <path d="M2 14 14 2" />
</svg>
