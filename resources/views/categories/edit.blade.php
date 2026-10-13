@extends('layouts.app')

@section('title', $category->isParent() ? '大分類を直す' : '小分類を直す')

@section('content')
    <a href="{{ route('categories.index') }}" class="d-inline-block mb-2">‹ 分類の管理</a>
    <h1 class="h4 mb-3">{{ $category->isParent() ? '大分類を直す' : '小分類を直す' }}</h1>

    @error('delete')
        <div class="alert alert-danger" role="alert" style="max-width: 560px">{{ $message }}</div>
    @enderror

    <div class="card" style="max-width: 560px">
        <div class="card-body">
            <form method="POST" action="{{ route('categories.update', $category) }}" novalidate>
                @csrf
                @method('PUT')
                @include('categories._form')
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">保存</button>
                    <button type="button" class="btn btn-outline-danger ms-auto" data-bs-toggle="modal" data-bs-target="#delete-dialog">削除</button>
                </div>
            </form>
        </div>
    </div>

    {{-- 削除の小窓（口座の管理と同じ形）。小分類がある大分類は、先に小分類をどうにかしてもらう --}}
    <div class="modal fade" id="delete-dialog" tabindex="-1" aria-labelledby="delete-dialog-title" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-body">
                    @if ($hasChildren)
                        <p id="delete-dialog-title" class="mb-0">小分類があるので削除できません。先に小分類を削除するか、別の大分類へ移してください</p>
                    @elseif ($recordCount === 0)
                        <p id="delete-dialog-title" class="mb-0">「{{ $category->name }}」を削除しますか？</p>
                    @elseif (! $category->is_active)
                        <p id="delete-dialog-title" class="mb-0">記録があるので削除できません。この分類は、もう「使わない」になっています。</p>
                    @else
                        <p id="delete-dialog-title" class="mb-0">記録があるので削除できません。『使わない』にしますか？</p>
                    @endif
                </div>
                <div class="modal-footer">
                    @if (! $hasChildren && $recordCount === 0)
                        <form method="POST" action="{{ route('categories.destroy', $category) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-danger">削除する</button>
                        </form>
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">やめる</button>
                    @elseif (! $hasChildren && $category->is_active)
                        <form method="POST" action="{{ route('categories.deactivate', $category) }}">
                            @csrf
                            @method('PATCH')
                            <button type="submit" class="btn btn-primary">「使わない」にする</button>
                        </form>
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">やめる</button>
                    @else
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">閉じる</button>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
