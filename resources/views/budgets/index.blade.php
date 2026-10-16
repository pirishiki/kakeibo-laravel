@extends('layouts.app')

@section('title', '予算を決める')

{{-- 予算を決める（画面設計書 5.11）。支出の分類を分類の管理と同じ順に並べ、金額の欄をまとめて保存する。
     「ふだんの予算（毎月）」と「特別な月」は URL で切りかえる（特別な月は ?month=2026-12）。
     予算の状況（使った額と棒）は、この画面ではなく月のまとめで見る --}}
@section('content')
    <h1 class="h4 mb-3">予算を決める</h1>

    <nav class="nav nav-pills mb-3" aria-label="予算の種類">
        <a href="{{ route('budgets.index') }}" @class(['nav-link', 'active' => $special === null])
            @if ($special === null) aria-current="page" @endif>ふだんの予算（毎月）</a>
        <a href="{{ route('budgets.index', ['month' => ($special ?? $first->addMonth())->format('Y-m')]) }}" @class(['nav-link', 'active' => $special !== null])
            @if ($special !== null) aria-current="page" @endif>特別な月</a>
    </nav>

    @if ($special === null)
        <p class="small text-secondary">毎月同じ予算です。変える・やめると、今月（{{ $first->format('n月') }}）から新しい予算になり、過ぎた月の予算の状況は変わりません。空の欄は「予算なし」です。</p>
    @else
        {{-- 特別な月は、今月から 12 か月先まで。過ぎた月の予算は直さない（過去の月のまとめが変わってしまうため） --}}
        <nav class="d-flex justify-content-center align-items-center gap-3 mb-2" aria-label="特別な月の切りかえ">
            @if ($special->greaterThan($first))
                <a href="{{ route('budgets.index', ['month' => $special->subMonth()->format('Y-m')]) }}"
                    class="btn btn-link text-decoration-none py-0">◀ {{ $special->subMonth()->format('n月') }}</a>
            @else
                <span class="btn btn-link text-decoration-none py-0 invisible" aria-hidden="true">◀ {{ $special->subMonth()->format('n月') }}</span>
            @endif
            <h2 class="h5 mb-0">{{ $special->format('Y年n月') }}</h2>
            @if ($special->lessThan($last))
                <a href="{{ route('budgets.index', ['month' => $special->addMonth()->format('Y-m')]) }}"
                    class="btn btn-link text-decoration-none py-0">{{ $special->addMonth()->format('n月') }} ▶</a>
            @else
                <span class="btn btn-link text-decoration-none py-0 invisible" aria-hidden="true">{{ $special->addMonth()->format('n月') }} ▶</span>
            @endif
        </nav>
        <p class="small text-secondary">この月だけ、ふだんとちがう予算にします（年末など）。空の欄は「ふだんのまま」です。</p>
    @endif

    @if ($parents->isEmpty())
        <div class="card">
            <div class="card-body text-center py-5">
                <p class="mb-3">「使う」支出の分類がありません</p>
                <a href="{{ route('categories.index') }}" class="btn btn-primary">分類の管理へ</a>
            </div>
        </div>
    @else
        <form method="POST" action="{{ route('budgets.update') }}" novalidate>
            @csrf
            @method('PUT')
            @if ($special !== null)
                <input type="hidden" name="month" value="{{ $special->format('Y-m') }}">
            @else
                {{-- 開いたときの月。月末に開いて月初めに保存したら、「○月から変えました」と知らせる --}}
                <input type="hidden" name="opened" value="{{ $useOld ? old('opened', $first->format('Y-m')) : $first->format('Y-m') }}">
            @endif

            <div class="card mb-3">
                <div class="list-group list-group-flush">
                    @foreach ($parents as $parent)
                        {{-- data-budget-group：小分類の合計が大分類の予算をこえたら、下の注意を出す（resources/js/app.js。打っているあいだも変わる） --}}
                        <div data-budget-group>
                            @include('budgets._row', ['category' => $parent, 'child' => false])
                            @foreach ($parent->children as $child)
                                @include('budgets._row', ['category' => $child, 'child' => true])
                            @endforeach
                            @php($groupOver = $over[$parent->id] ?? null)
                            {{-- 小分類の予算の合計が大分類の予算をこえても、まちがいとは限らないので、注意を出すだけで保存はできる --}}
                            <div class="list-group-item border-top-0 pt-0 ps-4 small text-danger" data-budget-over
                                data-parent-name="{{ $parent->name }}" @if ($groupOver === null) hidden @endif aria-live="polite">
                                @if ($groupOver !== null)
                                    小分類の予算の合計（{{ number_format($groupOver['children']) }}）が、{{ $parent->name }}の予算（{{ number_format($groupOver['parent']) }}）をこえています
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="text-end">
                <button type="submit" class="btn btn-primary">まとめて保存</button>
            </div>
        </form>
    @endif
@endsection
