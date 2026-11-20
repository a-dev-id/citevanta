<?php

namespace App\Http\Controllers;

use App\Enums\ContentStatus;
use App\Models\ContentPiece;
use App\Models\Hotel;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Download one content piece as a .txt (or .json for schema markup) file.
 */
class ContentDownloadController extends Controller
{
    public function __invoke(Hotel $hotel, ContentPiece $contentPiece): Response
    {
        $piece = $contentPiece;
        abort_unless($piece->status === ContentStatus::Ready, 404);

        $name = Str::slug($hotel->name.' '.$piece->type->value.' '.$piece->language.' v'.$piece->version).'.'.$piece->type->fileExtension();

        return response()->streamDownload(
            fn () => print ($piece->text()),
            $name,
            ['Content-Type' => $piece->type->fileExtension() === 'json' ? 'application/json' : 'text/plain; charset=UTF-8'],
        );
    }
}
