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
use App\Jobs\ProcessMangaArchive;
use App\Http\Requests\StoreMangaRequest;

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
        $mangaHash = session('selected_manga_hash'); // Get the manga hash from session
        $urls = $slice->map(fn($p, $index) => route('manga.image', ['manga' => $mangaHash, 'page' => $offset + $index + 1]));
        
        return view('_image_partials', ['urls' => $urls]);
    }

    // セキュリティ強化されたaddメソッド
    public function add(StoreMangaRequest $request)
    {
        $validated = $request->validated();
        $url = $validated['manga_url'];
        
        $hash = md5($url);
        $title = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME);
        $ext = strtolower(pathinfo($url, PHP_URL_EXTENSION));

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
        $extractPath = storage_path("app/manga_cache/{$hash}_extracted");

        $this->manageCacheSize($hash);

        if (!is_dir($extractPath) || !count(glob("$extractPath/*.webp"))) {
            ProcessMangaArchive::dispatch($manga);
            return view('_reader_processing');
        }

        $images = collect(glob("$extractPath/*.webp"))->sort()->values();

        if ($images->isEmpty()) abort(404, '画像が見つかりません');
        session(['current_manga_images' => $images->toArray()]);

        return view('_reader_content', [
            'title' => $manga->title,
            'totalPages' => $images->count(),
            'offset' => 0
        ]);
    }

    // セキュリティ強化されたimageメソッドをstreamImageに置き換え
    public function streamImage(Manga $manga, int $page)
    {
        // データベースの情報（$manga）から安全にパスを構築します。
        $extractPath = storage_path("app/manga_cache/{$manga->hash}_extracted");

        // ファイルをソートして一貫した順序を保証します。
        $files = collect(glob("$extractPath/*.webp"))->sort()->values();

        // ページ番号は配列のインデックスとして扱うため、-1します。
        $pageIndex = $page - 1;

        // ページ番号が有効範囲内か厳密にチェックします。
        if (!isset($files[$pageIndex])) {
            abort(404, 'Image not found.');
        }

        $imagePath = $files[$pageIndex];

        // ファイルの存在と正当性を再度確認します。
        if (!File::exists($imagePath)) {
            abort(404);
        }

        return response()->file($imagePath);
    }

    public function clearCache()
    {
        $dir = storage_path('app/manga_cache');
        if (is_dir($dir)) File::deleteDirectory($dir);
        return response()->json(['success' => true]);
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