@extends('layouts.app')
@section('content')
<h1 class="text-2xl font-bold mb-4">マンガライブラリ</h1>
<form hx-post="{{ route('add') }}" hx-target="#message" hx-swap="innerHTML" class="mb-4">
    @csrf
    <input type="text" name="manga_url" placeholder="ZIP/RAR 直接URL" required
           class="border px-2 py-1 w-3/4">
    <button class="bg-blue-600 text-white px-4 py-1">追加</button>
</form>
<div id="message"></div>
<div id="manga-list">
    @include('_manga_list')
</div>
@endsection
