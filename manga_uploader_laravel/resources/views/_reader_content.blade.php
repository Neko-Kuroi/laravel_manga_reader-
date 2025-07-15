<div>
    <p class="text-sm text-gray-600">ページ <span id="loaded">0</span> / <span id="total">{{ $totalPages }}</span></p>
    <div id="image-container" class="space-y-2"></div>
    <button id="load-more"
            hx-get="{{ url('/get_images') }}"
            hx-target="#image-container"
            hx-swap="beforeend"
            hx-include="#load-more"
            class="w-full bg-blue-600 text-white py-2 mt-4 rounded">
        ▼ もっと読み込む
    </button>
</div>
<script>
document.addEventListener('htmx:afterRequest', function(evt){
    if(evt.detail.pathInfo.requestPath === '/get_images'){
        const data = JSON.parse(evt.detail.xhr.responseText);
        const container = document.getElementById('image-container');
        data.images.forEach(src=>{
            const img = document.createElement('img');
            img.src = src;
            img.className = 'w-full';
            container.appendChild(img);
        });
        document.getElementById('loaded').textContent = data.current_offset + data.images.length;
        if(data.current_offset + data.images.length >= data.total_pages){
            document.getElementById('load-more').style.display='none';
        } else {
            evt.detail.elt.setAttribute('hx-get', '/get_images?offset=' + (data.current_offset + data.images.length));
        }
    }
});
</script>
