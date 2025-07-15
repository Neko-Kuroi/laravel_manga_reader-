以下は、Flask アプリの機能を Laravel 11（PHP 8.2）で再現したチュートリアルです。このチュートリアルでは、マンガアーカイブをオンラインで閲覧できるアプリケーションを作成します。

---

## 📚 チュートリアル：Laravel 11 でマンガビューアーを作成する

### 🔧 前提条件
- PHP 8.2 以上
- Composer インストール済み
- SQLite または任意のデータベース接続が可能
- ZIP アーカイブ拡張モジュール (`ZipArchive`)
- RAR アーカイブ対応: PHP の `ext-rar` 拡張モジュール、またはシステムに `unrar` もしくは `7z` (p7zip) コマンドラインツールがインストールされていること。
- [Intervention Image](https://image.intervention.io/) を使用して画像変換
- **セキュリティ強化**: URLの検証、パストラバーサル対策、ダウンロード時のエラーハンドリングが改善されています。

---

## ✅ ステップ 1: Laravel プロジェクト作成

```bash
composer create-project laravel/laravel manga_uploader_laravel
cd manga_uploader_laravel
composer require intervention/image
```

---

## ✅ ステップ 2: 環境設定 (.env)

`.env` ファイルを開き、以下の内容に置き換えます：

```ini
# データベース設定（SQLite 使用）
DB_CONNECTION=sqlite
DB_DATABASE=/absolute/path/to/manga_uploader_laravel/database/database.sqlite

# アップロード・キャッシュ保存先
FILESYSTEM_DISK=local
```

**初回のみ実行してください：**

```bash
touch database/database.sqlite
php artisan storage:link
```

---

## ✅ ステップ 3: マイグレーションとモデル作成

Manga モデルとマイグレーションファイルを作成します：

```bash
php artisan make:model Manga -m
```

### マイグレーションファイル編集:

```php
// database/migrations/xxxx_create_mangas_table.php
public function up()
{
    Schema::create('mangas', function (Blueprint $table) {
        $table->id();
        $table->string('hash')->unique(); // URL の MD5 ハッシュ
        $table->string('url'); // ZIP/RAR の直リンク
        $table->string('title'); // ファイル名
        $table->string('file_ext')->nullable(); // 拡張子
        $table->timestamps();
    });
}
```

モデルファイルも更新してください：

```php
// app/Models/Manga.php
class Manga extends Model
{
    protected $fillable = ['hash','url','title','file_ext'];
}
```

マイグレーションを実行します：

```bash
php artisan migrate
```

---

## ✅ ステップ 4: ルーティング設定

`routes/web.php` を開き、以下のように編集します：

```php
use App\Http\Controllers\MangaController;

Route::get('/', [MangaController::class, 'index'])->name('index');
Route::post('/add', [MangaController::class, 'add'])->name('add');
Route::post('/remove', [MangaController::class, 'remove'])->name('remove');
Route::get('/read', [MangaController::class, 'read'])->name('read');
Route::get('/reader', [MangaController::class, 'reader'])->name('reader');
Route::get('/reader_data', [MangaController::class, 'readerData'])->name('reader_data'); // ルート名を追加
Route::get('/get_images', [MangaController::class, 'getImages'])->name('get_images'); // ルート名を追加
Route::get('/image/{path}', [MangaController::class, 'image'])
     ->where('path', '.*')->name('image');
Route::post('/clear_cache', [MangaController::class, 'clearCache'])->name('clear_cache');
```

---

## ✅ ステップ 5: コントローラー作成

コントローラーを作成します：

```bash
php artisan make:controller MangaController
```

### コントローラーのコード

`app/Http/Controllers/MangaController.php` に以下のコードを貼り付けます：

```php
<?php
// MangaController.php - 修正版の主要部分

namespace App\Http\Controllers;

use App\Models\Manga;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use ZipArchive;
use RarArchive; // RAR対応
use Intervention\Image\Facades\Image as InterventionImage;

class MangaController extends Controller
{
    const CACHE_SIZE_LIMIT_MB = 270;
    const IMAGES_PER_LOAD = 5;
    const ALLOWED_DOMAINS = ['example.com', 'trusted-site.com']; // 許可されたドメイン

    public function index()
    {
        $mangas = Manga::orderBy('title')->get();
        return view('index', compact('mangas'));
    }

    // 不足していたgetImagesメソッドを追加
    public function getImages(Request $request)
    {
        $images = session('current_manga_images', []);
        $offset = (int)$request->query('offset', 0);
        $slice = collect($images)->slice($offset, self::IMAGES_PER_LOAD)->values();
        $urls = $slice->map(fn($p) => route('image', ['path' => ltrim(str_replace(storage_path('app'), '', $p), '/')]));
        
        return response()->json([
            'images' => $urls,
            'current_offset' => $offset,
            'total_pages' => count($images)
        ]);
    }

    // セキュリティ強化されたaddメソッド
    public function add(Request $request)
    {
        $url = urldecode($request->input('manga_url'));
        
        // URL検証を強化
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return response('<p class="text-red-600">無効なURLです。</p>');
        }
        
        // 許可されたドメインのみ許可（オプション）
        $domain = parse_url($url, PHP_URL_HOST);
        if (!empty(self::ALLOWED_DOMAINS) && !in_array($domain, self::ALLOWED_DOMAINS)) {
            return response('<p class="text-red-600">許可されていないドメインです。</p>');
        }
        
        $hash = md5($url);
        $title = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME);
        $ext = strtolower(pathinfo($url, PATHINFO_EXTENSION));

        if (!in_array($ext, ['zip','cbz','rar','cbr'])) {
            return response('<p class="text-red-600">無効なファイル形式です。</p>');
        }
        
        if (Manga::where('hash', $hash)->exists()) {
            return response('<p class="text-red-600">このURLは既に追加済みです。</p>');
        }

        Manga::create(compact('hash','url','title','file_ext'));
        $mangas = Manga::orderBy('title')->get();
        return view('_manga_list', compact('mangas'));
    }

    public function remove(Request $request)
    {
        $url = urldecode($request->input('url'));
        Manga::where('hash', md5($url))->delete();
        $mangas = Manga::orderBy('title')->get();
        return view('_manga_list', compact('mangas'));
    }

    public function read(Request $request)
    {
        $urlB64 = $request->query('url_b64');
        if (!$urlB64) return redirect()->route('index');

        try {
            $url = base64_decode(str_replace(['-','_'], ['+','/'], $urlB64));
            $hash = md5($url);
            if (!Manga::where('hash', $hash)->exists()) {
                return redirect()->route('index');
            }
            session(['selected_manga_hash' => $hash]);
            return redirect()->route('reader');
        } catch (\Throwable $e) {
            return redirect()->route('index');
        }
    }

    public function reader()
    {
        if (!session('selected_manga_hash')) return redirect()->route('index');
        $title = Manga::where('hash', session('selected_manga_hash'))->value('title') ?? 'Unknown';
        return view('reader', compact('title'));
    }

    public function readerData()
    {
        $hash = session('selected_manga_hash');
        if (!$hash) abort(404);

        $manga = Manga::where('hash', $hash)->firstOrFail();
        $archivePath = storage_path("app/manga_cache/{$hash}.{$manga->file_ext}");
        $extractPath = storage_path("app/manga_cache/{$hash}_extracted");

        $this->manageCacheSize($hash);

        if (!is_dir($extractPath) || !count(glob("$extractPath/*.webp"))) {
            $this->downloadFile($manga->url, $archivePath);
            $this->extractArchive($archivePath, $extractPath, $manga->file_ext);
        }

        $images = collect(glob("$extractPath/*.webp"))->sort()->values();

        if ($images->isEmpty()) abort(404, '画像が見つかりません');
        session(['current_manga_images' => $images->toArray()]);

        return view('_reader_content', [
            'title' => $manga->title,
            'totalPages' => $images->count(), // Corrected variable name
            'offset' => 0
        ]);
    }

    // セキュリティ強化されたimageメソッド
    public function image($path)
    {
        // パストラバーサル攻撃を防ぐ
        $path = str_replace(['../', '..\\'], '', $path);
        $full = storage_path("app/$path");
        
        // storage/app配下のファイルのみ許可
        $allowedPath = storage_path('app/manga_cache');
        if (strpos(realpath($full), realpath($allowedPath)) !== 0) {
            abort(403, 'Access denied');
        }
        
        if (!File::exists($full)) {
            abort(404);
        }
        
        return response()->file($full);
    }

    public function clearCache()
    {
        $dir = storage_path('app/manga_cache');
        if (is_dir($dir)) File::deleteDirectory($dir);
        return response()->json(['success' => true]);
    }

    private function downloadFile($url, $savePath)
    {
        // URL検証
        if (!$this->validateUrl($url)) {
            throw new \Exception("Invalid URL: $url");
        }
        
        if (File::exists($savePath)) return;
        
        $dir = dirname($savePath);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        
        $ch = curl_init($url);
        $fp = fopen($savePath, 'wb');
        
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; MangaViewer/1.0)',
        ]);
        
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);
        fclose($fp);
        
        if ($result === false || $httpCode !== 200) {
            if (File::exists($savePath)) unlink($savePath);
            throw new \Exception("Download failed: {$error} (HTTP {$httpCode})");
        }
    }

    // RAR対応を追加したextractArchiveメソッド
    private function extractArchive($archive, $extractTo, $ext)
    {
        if (!is_dir($extractTo)) {
            mkdir($extractTo, 0755, true);
        }
        
        if (in_array($ext, ['zip','cbz'])) {
            $this->extractZip($archive, $extractTo);
        } elseif (in_array($ext, ['rar','cbr'])) {
            $this->extractRar($archive, $extractTo);
        }
    }

    private function extractZip($archive, $extractTo)
    {
        $zip = new ZipArchive;
        if ($zip->open($archive) === TRUE) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (!preg_match('/\.(jpg|jpeg|png|gif|bmp)$/i', $name)) continue;
                
                $data = $zip->getFromIndex($i);
                if ($data === false) continue;
                
                try {
                    $img = InterventionImage::make($data)
                        ->resize(1200, 1600, function($constraint) {
                            $constraint->aspectRatio();
                        });
                    $img->save($extractTo.'/'.sprintf('%04d.webp', $i), 80, 'webp');
                } catch (\Exception $e) {
                    Log::error("Image processing failed: " . $e->getMessage());
                }
            }
            $zip->close();
        }
    }

    private function extractRar($archive, $extractTo)
    {
        // PHPのRarArchiveクラスを使用
        if (class_exists('RarArchive')) {
            $rar = RarArchive::open($archive);
            if ($rar) {
                $entries = $rar->getEntries();
                $i = 0;
                foreach ($entries as $entry) {
                    if (!preg_match('/\.(jpg|jpeg|png|gif|bmp)$/i', $entry->getName())) continue;
                    
                    $data = $entry->getStream();
                    if ($data === false) continue;
                    
                    try {
                        $img = InterventionImage::make(stream_get_contents($data))
                            ->resize(1200, 1600, function($constraint) {
                                $constraint->aspectRatio();
                            });
                        $img->save($extractTo.'/'.sprintf('%04d.webp', $i), 80, 'webp');
                        $i++;
                    } catch (\Exception $e) {
                        Log::error("Image processing failed: " . $e->getMessage());
                    }
                }
                $rar->close();
            }
        } else {
            // システムコマンドを使用（unrarまたは7zが必要）
            $this->extractRarWithCommand($archive, $extractTo);
        }
    }

    private function extractRarWithCommand($archive, $extractTo)
    {
        $tempDir = $extractTo . '_temp';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        
        // unrarコマンドを試す
        $command = "unrar x " . escapeshellarg($archive) . " " . escapeshellarg($tempDir);
        exec($command, $output, $return_var);
        
        if ($return_var !== 0) {
            // 7zコマンドを試す
            $command = "7z x " . escapeshellarg($archive) . " -o" . escapeshellarg($tempDir);
            exec($command, $output, $return_var);
        }
        
        if ($return_var === 0) {
            // 解凍されたファイルを処理
            $files = glob($tempDir . '/*.{jpg,jpeg,png,gif,bmp}', GLOB_BRACE);
            sort($files);
            
            foreach ($files as $i => $file) {
                try {
                    $img = InterventionImage::make($file)
                        ->resize(1200, 1600, function($constraint) {
                            $constraint->aspectRatio();
                        });
                    $img->save($extractTo.'/'.sprintf('%04d.webp', $i), 80, 'webp');
                } catch (\Exception $e) {
                    Log::error("Image processing failed: " . $e->getMessage());
                }
            }
        }
        
        // 一時ディレクトリを削除
        if (is_dir($tempDir)) {
            File::deleteDirectory($tempDir);
        }
    }

    private function manageCacheSize($currentHash)
    {
        $cacheDir = storage_path('app/manga_cache');
        if (!is_dir($cacheDir)) return;
        $max = self::CACHE_SIZE_LIMIT_MB * 1024 * 1024;
        $total = 0; $items = [];
        foreach (scandir($cacheDir) as $f) {
            if ($f === '.' || $f === '..') continue;
            $path = "$cacheDir/$f";
            $hash = str_replace(['_extracted', '.zip','.rar','.cbz','.cbr'], '', $f);
            if (!isset($items[$hash])) {
                $items[$hash] = ['mtime' => 0, 'size' => 0];
            }
            $items[$hash]['mtime'] = max($items[$hash]['mtime'], filemtime($path));
            $items[$hash]['size']  = $items[$hash]['size'] + filesize($path);
            $total += filesize($path);
        }
        if ($total <= $max) return;
        uasort($items, fn($a,$b) => $a['mtime'] <=> $b['mtime']);
        foreach ($items as $hash=>$item) {
            if ($total <= $max) break;
            if ($hash === $currentHash) continue;
            foreach (glob("$cacheDir/$hash*") as $p) {
                is_dir($p) ? File::deleteDirectory($p) : unlink($p);
            }
            $total -= $item['size'];
        }
    }
}
```

---

## ✅ ステップ 6: Blade テンプレート作成

Blade テンプレートを `resources/views/` 配下に作成します。

### 共通レイアウト: `layouts/app.blade.php`

```blade
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
```

### トップページ: `index.blade.php`

```blade
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
```

### リスト表示部分: `_manga_list.blade.php`

```blade
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
```

### リーダー画面: `reader.blade.php`

```blade
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
```

### 読み込み用部分テンプレート: `_reader_content.blade.php`

```blade
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
```

---

## ✅ ステップ 7: サーバー起動

プロジェクトディレクトリで次のコマンドを実行します：

```bash
php artisan serve
```

ブラウザでアクセス：
```
http://127.0.0.1:8000
```

---

## ✅ 補足情報

- **RAR 対応**: PHP の `ext-rar` 拡張モジュール、またはシステムに `unrar` もしくは `7z` (p7zip) コマンドラインツールをインストールすることでRARアーカイブを扱えます。
- **セキュリティ強化**: URLの検証（SSRF対策、許可ドメイン設定）、パストラバーサル攻撃からの保護、ダウンロード時の詳細なエラーハンドリングが実装されています。
- Tailwind CSS は CDN を使用しているため、npm や npm build は不要です。
- キャッシュ管理により、指定されたサイズを超えると古いキャッシュが自動的に削除されます。

---

これで Laravel 11 で動作する、よりセキュアで機能的なマンガビューアーが完成しました！
