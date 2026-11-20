<?php

namespace App\Services\Deploy;

use FilesystemIterator;
use Illuminate\Support\Facades\File;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

/**
 * Builds a release zip for hosts without SSH: the app with vendor/ (no dev
 * packages) and public/build/, without secrets, tests or local files.
 */
final class ReleasePackager
{
    /** Folders (and everything in them) never shipped. */
    private const EXCLUDED_DIRS = [
        '.git', '.github', '.idea', '.vscode', '.claude', '.cursor', '.junie', '.agents', '.ai',
        'node_modules', 'tests', 'vendor', 'releases', 'public/hot', 'public/storage',
    ];

    /** Files never shipped. */
    private const EXCLUDED_FILES = [
        '.phpunit.result.cache', '.mcp.json', 'boost.json', 'AGENTS.md', 'CLAUDE.md',
        'phpunit.xml', 'database/database.sqlite', 'public/hot',
    ];

    /** .env files that are templates (everything else matching .env* is excluded). */
    private const ENV_TEMPLATES = ['.env.example', '.env.production.example'];

    /**
     * Whether a project file (path relative to the project root) goes into the zip.
     */
    public static function shouldInclude(string $relativePath): bool
    {
        $path = ltrim(str_replace('\\', '/', $relativePath), '/');

        if ($path === '') {
            return false;
        }

        foreach (self::EXCLUDED_DIRS as $dir) {
            if ($path === $dir || str_starts_with($path, $dir.'/')) {
                return false;
            }
        }

        if (in_array($path, self::EXCLUDED_FILES, true)) {
            return false;
        }

        if (str_starts_with(basename($path), '.env') && ! in_array($path, self::ENV_TEMPLATES, true)) {
            return false;
        }

        // storage/ and bootstrap/cache/: ship the empty folder structure only
        // (logs, sessions, cached views/config belong to each server).
        if (str_starts_with($path, 'storage/') || str_starts_with($path, 'bootstrap/cache/')) {
            return basename($path) === '.gitignore';
        }

        return true;
    }

    /** Keys that must be empty (or a placeholder) in a shipped .env template. */
    private const SECRET_KEYS = [
        'APP_KEY', 'OPENAI_API_KEY', 'CRON_TOKEN', 'XENDIT_SECRET_KEY', 'XENDIT_CALLBACK_TOKEN',
        'GOOGLE_CLIENT_SECRET', 'TURNSTILE_SECRET_KEY',
    ];

    /** Values accepted as placeholders for *_PASSWORD keys. */
    private const PLACEHOLDERS = ['', 'null', 'CHANGE-ME', 'change-me'];

    /**
     * Keys in an .env template that hold what looks like a real secret.
     * Returns key names only, never the values.
     *
     * @return list<string>
     */
    public static function secretsIn(string $envContents): array
    {
        $found = [];

        foreach (preg_split('/\R/', $envContents) ?: [] as $line) {
            if (! preg_match('/^\s*([A-Z0-9_]+)\s*=\s*(.*)$/', $line, $m)) {
                continue;
            }

            [$key, $value] = [$m[1], trim($m[2], " \t\"'")];
            $isSecretKey = in_array($key, self::SECRET_KEYS, true) || str_ends_with($key, '_PASSWORD');

            if (($isSecretKey && ! in_array($value, self::PLACEHOLDERS, true))
                || preg_match('/sk-[A-Za-z0-9_\-]{20,}/', $value)) {
                $found[] = $key;
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Copy the shippable project files into $target.
     */
    public function copyProject(string $source, string $target): int
    {
        $count = 0;
        $source = rtrim(str_replace('\\', '/', $source), '/');

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($files as $file) {
            $relative = substr(str_replace('\\', '/', $file->getPathname()), strlen($source) + 1);

            if ($file->isDir() || ! self::shouldInclude($relative)) {
                continue;
            }

            File::ensureDirectoryExists(dirname($target.'/'.$relative));
            File::copy($file->getPathname(), $target.'/'.$relative);
            $count++;
        }

        return $count;
    }

    /**
     * Delete every release zip in $folder except $keep (the one just built).
     * Only *.zip files are touched.
     *
     * @return int number of zips deleted
     */
    public function pruneOldReleases(string $folder, string $keep): int
    {
        $keep = realpath($keep) ?: $keep;
        $deleted = 0;

        foreach (glob(rtrim($folder, '/\\').DIRECTORY_SEPARATOR.'*.zip') ?: [] as $zip) {
            if ((realpath($zip) ?: $zip) !== $keep && @unlink($zip)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Zip a folder; entries are relative to the folder (extract straight into the app folder).
     */
    public function zip(string $folder, string $zipPath): void
    {
        File::ensureDirectoryExists(dirname($zipPath));
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Could not create {$zipPath}.");
        }

        $folder = rtrim(str_replace('\\', '/', $folder), '/');
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($files as $file) {
            $relative = substr(str_replace('\\', '/', $file->getPathname()), strlen($folder) + 1);
            $file->isDir() ? $zip->addEmptyDir($relative) : $zip->addFile($file->getPathname(), $relative);
        }

        $zip->close();
    }
}
