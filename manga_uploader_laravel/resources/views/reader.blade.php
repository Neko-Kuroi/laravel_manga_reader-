@extends('layouts.app')
@section('content')
<div class="flex justify-between items-center mb-2">
    <h1 class="text-xl font-bold">{{ $title }}</h1>
    <a href="{{ route('index') }}" class="text-blue-600">↩️ リストに戻る</a>
</div>
<div id="reader-content"
     hx-get="{{ route('reader_data') }}" hx-trigger="load">
    <p class="text-gray-500">読み込み中…</p>
</div>
@endsection
