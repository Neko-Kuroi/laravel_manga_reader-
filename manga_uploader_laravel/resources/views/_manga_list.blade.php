<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
    @foreach($mangas as $manga)
        <div class="bg-white shadow p-3 rounded">
            <p class="font-bold truncate">{{ $manga->title }}</p>
            <div class="flex gap-2 mt-2">
                <a href="{{ route('read',['url_b64'=>base64_encode($manga->url)]) }}"
                   class="bg-green-600 text-white px-2 py-1 rounded text-sm">読む</a>
                <button hx-post="{{ route('remove') }}" hx-target="#manga-list" hx-swap="outerHTML"
                        hx-vals='{"url":"{{ $manga->url }}"}' class="bg-red-600 text-white px-2 py-1 rounded text-sm">
                    削除
                </button>
            </div>
        </div>
    @endforeach
</div>
