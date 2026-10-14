@extends('layouts.app')

@section('title', '定期的な記録を直す')

{{-- 定期的な記録の変更（画面設計書 5.10）。入力欄は Livewire の部品（app/Livewire/RecurringForm.php） --}}
@section('content')
    <livewire:recurring-form :recurring="$recurring" />
@endsection
