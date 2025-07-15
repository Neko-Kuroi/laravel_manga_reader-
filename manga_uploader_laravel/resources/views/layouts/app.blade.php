<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>Manga Uploader</title>
    <script src="https://unpkg.com/htmx.org@1.9.10"></script>
    <script src="https://cdn.tailwindcss.com"></script>
    @stack('styles')
</head>
<body class="bg-gray-100 text-gray-800">
    <div class="container mx-auto p-4">
        @yield('content')
    </div>
    @stack('scripts')
</body>
</html>
