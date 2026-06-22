<?php

use Illuminate\Support\Facades\Route;

Route::get('/__paths', function () {
    return response()->json([
        'document_root' => request()->server('DOCUMENT_ROOT'),
        'public_path' => public_path(),
        'base_path' => base_path(),
        'app_url' => config('app.url'),
        'uploads_root' => config('filesystems.disks.public_uploads.root'),
    ]);
});

Route::get('/', function () {
    return view('welcome');
});
