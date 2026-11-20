<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Fallback for hosts that do not allow symlinks: serves files from the
 * "public" disk (storage/app/public) at /storage/{path}. Only that disk,
 * never other folders: ".." segments are refused.
 */
class StorageFileController extends Controller
{
    public function __invoke(string $path): BinaryFileResponse
    {
        abort_if($path === '' || str_contains($path, '..') || str_contains($path, "\0"), 404);

        $disk = Storage::disk('public');
        abort_unless($disk->exists($path), 404);

        return response()->file($disk->path($path), [
            'Cache-Control' => 'public, max-age=604800',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
