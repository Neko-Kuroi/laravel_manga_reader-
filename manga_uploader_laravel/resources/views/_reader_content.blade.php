<div>
    <p class="text-sm text-gray-600">ページ <span id="loaded">0</span> / <span id="total">{{ $totalPages }}</span></p>
    <div id="image-container" class="space-y-2"></div>
    <button id="load-more"
            hx-get="{{ route('get_images') }}"
            hx-target="#image-container"
            hx-swap="beforeend"
            hx-include="#load-more"
            class="w-full bg-blue-600 text-white py-2 mt-4 rounded">
        ▼ もっと読み込む
    </button>
</div>
<script>
document.addEventListener('htmx:afterRequest', function(evt){
    if(evt.detail.pathInfo.requestPath === '{{ route('get_images') }}'){
        const data = evt.detail.xhr.responseText;
        const container = document.getElementById('image-container');
        // HTMX will automatically append the HTML returned by the server
        
        // Update loaded page count (assuming server returns current_offset and total_pages in a header or similar)
        // For simplicity, we'll just update the offset for the next request
        const currentOffset = parseInt(evt.detail.elt.getAttribute('hx-get').split('offset=')[1] || 0);
        const totalPages = parseInt(document.getElementById('total').textContent);
        const loadedImagesCount = container.children.length;

        document.getElementById('loaded').textContent = loadedImagesCount;

        if(loadedImagesCount >= totalPages){
            document.getElementById('load-more').style.display='none';
        } else {
            evt.detail.elt.setAttribute('hx-get', '{{ route('get_images') }}?offset=' + loadedImagesCount);
        }
    }
});
</script>
