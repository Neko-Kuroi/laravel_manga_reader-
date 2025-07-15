<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MangaController;

Route::get('/', [MangaController::class, 'index'])->name('index');
Route::post('/add', [MangaController::class, 'add'])->name('add');
Route::post('/remove', [MangaController::class, 'remove'])->name('remove');
Route::get('/read', [MangaController::class, 'read'])->name('read');
Route::get('/reader', [MangaController::class, 'reader'])->name('reader');
Route::get('/reader_data', [MangaController::class, 'readerData'])->name('reader_data');
Route::get('/get_images', [MangaController::class, 'getImages'])->name('get_images');
Route::get('/reader/{manga:hash}/image/{page}', [MangaController::class, 'streamImage'])
     ->name('manga.image');
Route::post('/clear_cache', [MangaController::class, 'clearCache'])->name('clear_cache');