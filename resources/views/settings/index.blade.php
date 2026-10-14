@extends('layouts.app')

@section('title', '設定')

{{-- 設定（画面設計書 5.12）。たまにしか使わない画面へのリンクを並べる。
     定期的な記録・予算・貯金の目標（第 2・第 3 段階）は、その段階になってから「毎月の予定と目標」として足す --}}
@section('content')
    <h1 class="h4 mb-3">設定</h1>

    {{-- 使い始めに設定する順（口座 → 分類 → 相手）に並べる。2 行目に、何の画面かと「使う」ものの件数 --}}
    <h2 class="h6 text-secondary">お金の入れ物と分け方</h2>
    <div class="list-group mb-4">
        @include('settings._row', [
            'href' => route('accounts.index'),
            'label' => '口座',
            'detail' => '銀行口座・現金・交通系 IC・カード（'.$accountCount.' 件）',
        ])
        @include('settings._row', [
            'href' => route('categories.index'),
            'label' => '分類',
            'detail' => '食費・日用品など（支出 '.$expenseCategoryCount.'・収入 '.$incomeCategoryCount.'）',
        ])
        @include('settings._row', [
            'href' => route('partners.index'),
            'label' => '相手',
            'detail' => 'お店・会社など（'.$partnerCount.' 件）',
        ])
    </div>

    {{-- 設定の中にログアウトがあると思う人が多いため、スマホでは画面の上に加えてここにも置く。
         パソコンでは設定の画面をメニューから開かない（左のメニューに全部出る）ので、出さない。
         ログアウトは状態を変える操作なので、リンクではなく、合言葉（@csrf）つきのフォームで送る --}}
    <h2 class="h6 text-secondary d-lg-none">ログイン</h2>
    <form method="POST" action="{{ route('logout') }}" class="list-group d-lg-none">
        @csrf
        <button type="submit" class="list-group-item list-group-item-action d-flex align-items-center gap-2 text-start">
            <span class="flex-grow-1 min-w-0">
                <span class="d-block">ログアウト</span>
                <span class="d-block small text-secondary text-break">{{ auth()->user()->email }} でログイン中</span>
            </span>
            <span class="text-secondary" aria-hidden="true">›</span>
        </button>
    </form>
@endsection
