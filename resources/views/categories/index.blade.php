@extends('layouts.app')

@section('title', '分類の管理')

{{-- 分類の管理の一覧（画面設計書 5.8）。支出の分類と収入の分類を別の四角に出し、大分類の下に小分類を 1 段下げて全部出す --}}
@section('content')
    <h1 class="h4 mb-2">分類の管理</h1>
    <p class="small text-secondary d-flex align-items-center gap-1 flex-wrap mb-3">
        <x-grip-icon width="14" height="14" /> を押さえたまま上下に動かすと、順番を変えられます
    </p>

    <div class="row g-4">
        @foreach (\App\Enums\CategoryKind::cases() as $kind)
            @php($kindParents = $parents->get($kind->value, collect()))
            <section class="col-lg-6" aria-labelledby="kind-{{ $kind->value }}">
                <div class="card">
                    <div class="card-header bg-white">
                        <h2 id="kind-{{ $kind->value }}" class="h6 mb-0">{{ $kind->label() }}の分類</h2>
                    </div>
                    {{-- data-sortable：この中の分類（大分類どうし）を、ドラッグで並べかえられる。値は、並べた順番の送り先 --}}
                    <div class="list-group list-group-flush" data-sortable="{{ route('categories.reorder') }}">
                        @foreach ($kindParents as $parent)
                            {{-- 大分類と、その下の小分類・「＋ 小分類を追加」を 1 つのまとまりにし、大分類を動かすと小分類もいっしょに動くようにする --}}
                            <div class="category-group" data-id="{{ $parent->id }}" @unless ($parent->is_active) data-fixed @endunless>
                                @include('categories._row', ['category' => $parent, 'muted' => ! $parent->is_active])
                                {{-- 小分類どうしは、同じ大分類の中だけで並べかえられる --}}
                                <div class="list-group list-group-flush" data-sortable="{{ route('categories.reorder') }}">
                                    @foreach ($parent->children as $child)
                                        {{-- 大分類が「使わない」なら、その下の小分類も選べないので、薄い字にする --}}
                                        <div data-id="{{ $child->id }}" @if (! $child->is_active || ! $parent->is_active) data-fixed @endif>
                                            @include('categories._row', ['category' => $child, 'muted' => ! $child->is_active || ! $parent->is_active])
                                        </div>
                                    @endforeach
                                </div>
                                <div class="list-group-item ps-5 py-1">
                                    <a href="{{ route('categories.create', ['parent' => $parent->id]) }}" class="small">＋ 小分類を追加</a>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div class="card-body">
                        <a href="{{ route('categories.create', ['kind' => $kind->value]) }}" class="btn btn-primary btn-sm">＋ 大分類を追加</a>
                    </div>
                </div>
            </section>
        @endforeach
    </div>
@endsection
