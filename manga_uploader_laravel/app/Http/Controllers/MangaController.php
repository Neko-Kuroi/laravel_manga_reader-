<?php

namespace App\Http\Controllers;

use App\Models\Manga;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use ZipArchive;
use RarArchive;
use Intervention\Image\Laravel\Facades\Image as InterventionImage;

class MangaController extends Controller
{
    const CACHE_SIZE_LIMIT_MB = 270;
    const IMAGES_PER_LOAD = 5;

    public function index()
    {
        $mangas = Manga::orderBy('title')->get();
        return view('index', compact('mangas'));
    }

    public function add(Request $request)
    {
        $url = urldecode($request->input('manga_url'));
        $hash = md5($url);
        $title = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME);
        $ext = strtolower(pathinfo($url, PATHINFO_EXTENSION));

        if (!in_array($ext, ['zip','cbz','rar','cbr'])) {
            return response('<p class="text-red-600">無効なファイル形式です。</p>');
        }
        if (Manga::where('hash', $hash)->exists()) {
            return response('<p class="text-red-600">このURLは既に追加済みです。</p>');
        }

        Manga::create(['hash' => $hash, 'url' => $url, 'title' => $title, 'file_ext' => $ext]);
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
            'total_pages' => $images->count(),
            'offset' => 0
        ]);
    }

    public function getImages(Request $request)
    {
        $images = session('current_manga_images', []);
        $offset = (int)$request->query('offset', 0);
        $slice = array_slice($images, $offset, self::IMAGES_PER_LOAD);
        $urls = collect($slice)->map(fn($p) => route('image', ['path' => ltrim(str_replace(storage_path('app'), '', $p), '/')]));
        
        return response()->json([
            'images' => $urls,
            'current_offset' => $offset,
            'total_pages' => count($images)
        ]);
    }

    public function image($path)
    {
        $full = storage_path("app/$path");
        if (!File::exists($full)) abort(404);
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
        if (File::exists($savePath)) return;
        $dir = dirname($savePath);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $ch = curl_init($url);
        $fp = fopen($savePath, 'wb');
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        curl_exec($ch);
        curl_close($ch);
        fclose($fp);
    }

    private function extractArchive($archive, $extractTo, $ext)
    {
        if (!is_dir($extractTo)) mkdir($extractTo, 0755, true);
        if (in_array($ext, ['zip','cbz'])) {
            $zip = new ZipArchive;
            if ($zip->open($archive) === TRUE) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = $zip->getNameIndex($i);
                    if (!preg_match('/\.(jpg|jpeg|png)$/i', $name)) continue;
                    $data = $zip->getFromIndex($i);
                    $img = InterventionImage::read($data)->resize(1200, 1600, fn($c)=>$c->aspectRatio());
                    $img->toWebp(80)->save($extractTo.'/'.sprintf('%04d.webp', $i));
                }
                $zip->close();
            }
        } elseif (in_array($ext, ['rar','cbr'])) {
            try {
                $rar = RarArchive::open($archive);
                $entries = $rar->getEntries();
                $imageCount = 0;
                foreach ($entries as $entry) {
                    $name = $entry->getName();
                    if (!preg_match('/\.(jpg|jpeg|png)$/i', $name)) continue;
                    $stream = $entry->getStream();
                    if ($stream) {
                        $data = stream_get_contents($stream);
                        fclose($stream);
                        $img = InterventionImage::read($data)->resize(1200, 1600, fn($c)=>$c->aspectRatio());
                        $img->toWebp(80)->save($extractTo.'/'.sprintf('%04d.webp', $imageCount++));
                    }
                }
                $rar->close();
            } catch (\Exception $e) {
                Log::error("RAR extraction failed: " . $e->getMessage());
            }
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