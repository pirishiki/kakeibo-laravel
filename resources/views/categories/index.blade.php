@extends('layouts.app')

@section('title', '分類の管理')

{{-- 分類の管理の一覧（画面設計書 5.8）。支出の分類と収入の分類を別の四角に出し、大分類の下に小分類を 1 段下げて全部出す --}}
@section('content')
    <h1 class="h4 mb-3">分類の管理</h1>

    <div class="row g-4">
        @foreach (\App\Enums\CategoryKind::cases() as $kind)
            @php($kindParents = $parents->get($kind->value, collect()))
            <section class="col-lg-6" aria-labelledby="kind-{{ $kind->value }}">
                <div class="card">
                    <div class="card-header bg-white">
                        <h2 id="kind-{{ $kind->value }}" class="h6 mb-0">{{ $kind->label() }}の分類</h2>
                    </div>
                    <div class="list-group list-group-flush">
                        @foreach ($kindParents as $parent)
                            @include('categories._row', [
                                'category' => $parent,
                                'isFirst' => $loop->first,
                                'isLast' => $loop->last,
                                'muted' => ! $parent->is_active,
                            ])
                            @foreach ($parent->children as $child)
                                {{-- 大分類が「使わない」なら、その下の小分類も選べないので、薄い字にする --}}
                                @include('categories._row', [
                                    'category' => $child,
                                    'isFirst' => $loop->first,
                                    'isLast' => $loop->last,
                                    'muted' => ! $child->is_active || ! $parent->is_active,
                                ])
                            @endforeach
                            <div class="list-group-item ps-5 py-1">
                                <a href="{{ route('categories.create', ['parent' => $parent->id]) }}" class="small">＋ 小分類を追加</a>
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
