<?php

namespace App\Jobs;

use App\Models\Manga;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use ZipArchive;
use RarArchive;
use Intervention\Image\Facades\Image as InterventionImage;

class ProcessMangaArchive implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $manga;

    /**
     * Create a new job instance.
     */
    public function __construct(Manga $manga)
    {
        $this->manga = $manga;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $manga = $this->manga;
        $archivePath = storage_path("app/manga_cache/{$manga->hash}.{$manga->file_ext}");
        $extractPath = storage_path("app/manga_cache/{$manga->hash}_extracted");

        try {
            $this->downloadFile($manga->url, $archivePath);
            $this->extractArchive($archivePath, $extractPath, $manga->file_ext);
        } catch (\Exception $e) {
            Log::error("Manga processing failed for {$manga->title}: " . $e->getMessage());
            // Optionally, handle failed jobs (e.g., notify user, retry)
        }
    }

    private function downloadFile($url, $savePath)
    {
        // URL検証 (MangaControllerから移動)
        // Note: validateUrl method is not directly available here. 
        // For a job, you might re-implement a simpler validation or assume it's validated before dispatch.
        // For now, I'll remove the validateUrl call as it's a private method of MangaController.
        // If strict validation is needed here, it should be passed as a dependency or re-implemented.
        
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
}