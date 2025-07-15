@extends('layouts.app')
@section('content')
<div class="flex justify-center items-center h-screen">
    <div class="text-center">
        <p class="text-xl text-gray-700">マンガを準備中...</p>
        <p class="text-gray-500">しばらくお待ちください。この処理には時間がかかる場合があります。</p>
        <div class="mt-4">
            <a href="{{ route('index') }}" class="text-blue-600">↩️ リストに戻る</a>
        </div>
    </div>
</div>
@endsection