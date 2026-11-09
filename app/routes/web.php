<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AlbumImageController;
use App\Http\Controllers\Admin\ArtistImageController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\MontageController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/', [AuthController::class, 'index'])->name('login');
Route::post('/locale', [LocaleController::class, 'store'])->name('locale');
Route::get('/auth/mastodon/callback', [AuthController::class, 'callbackMastodon'])->name('auth.mastodon.callback');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::get('/settings', [SettingsController::class, 'index'])
    ->middleware('auth')
    ->name('settings');

Route::get('/montage/{hash}', [MontageController::class, 'show'])
    ->where('hash', '[a-fA-F0-9]{32}')
    ->name('montage');

// Cached artist images and album covers, consumed by the Filament panel.
Route::get('/admin/artists/{artist}/image', [ArtistImageController::class, 'show'])
    ->middleware('auth:admin')
    ->name('admin.artists.image');

Route::get('/admin/albums/{album}/image', [AlbumImageController::class, 'show'])
    ->middleware('auth:admin')
    ->name('admin.albums.image');
