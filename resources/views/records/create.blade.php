@extends('layouts.app')

@section('title', '記録する')

{{-- 記録の登録（画面設計書 5.4）。入力欄は Livewire の部品（app/Livewire/RecordForm.php） --}}
@section('content')
    <h1 class="h4 mb-3">記録する</h1>
    <livewire:record-form />
@endsection
