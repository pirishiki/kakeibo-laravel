@extends('layouts.app')

@section('title', '残高を合わせる')

{{-- 残高を合わせる（画面設計書 5.7）。見出し・入力欄・最近の記録は Livewire の部品（app/Livewire/AdjustBalance.php） --}}
@section('content')
    <livewire:adjust-balance :account="$account" />
@endsection
