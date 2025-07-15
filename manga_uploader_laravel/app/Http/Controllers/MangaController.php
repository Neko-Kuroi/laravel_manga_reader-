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