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

        // A Google service account key stays on the server: it is never packed.
        if (preg_match(self::KEY_FILE_PATTERN, $path) === 1) {
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

        // Local helper files at the project root: setup.log, other logs, and
        // check or cost-probe scripts. They never belong on the server.
        if (! str_contains($path, '/')
            && (str_ends_with($path, '.log') || preg_match('/^(check|probe|cost-probe)[-_.]|[-_.]probe\.\w+$/i', $path) === 1)) {
            return false;
        }

        return true;
    }

    /**
     * The site's document root inside the hosting account's home folder,
     * from the production APP_URL: "https://citevanta.a-dashboard.com" ->
     * "public_html/citevanta.a-dashboard.com". Null when APP_URL has no host.
     */
    public static function publicFolderFor(string $envContents): ?string
    {
        if (preg_match('/^APP_URL=(\S+)/m', $envContents, $match) !== 1) {
            return null;
        }

        $host = strtolower((string) parse_url(trim($match[1], " \t\"'"), PHP_URL_HOST));

        return $host !== '' ? 'public_html/'.$host : null;
    }

    /**
     * Files of public/ that never go into public_html/: the server's own
     * index.php (it has the usePublicPath edit) and .htaccess, plus build
     * leftovers. Paths are relative to public/.
     */
    public static function shouldIncludePublic(string $relativePath): bool
    {
        $path = ltrim(str_replace('\\', '/', $relativePath), '/');

        if ($path === '' || in_array($path, ['index.php', '.htaccess', '.gitignore', 'hot'], true)) {
            return false;
        }

        return ! str_starts_with($path, 'storage/') && $path !== 'storage';
    }

    /**
     * Copy public/ into $target (the public_html/ folder of the zip).
     *
     * @return int number of files copied
     */
    public function copyPublic(string $source, string $target): int
    {
        $source = rtrim(str_replace('\\', '/', $source), '/');
        $count = 0;
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($files as $file) {
            $relative = substr(str_replace('\\', '/', $file->getPathname()), strlen($source) + 1);

            if ($file->isDir() || ! self::shouldIncludePublic($relative)) {
                continue;
            }

            if (! is_dir(dirname($target.'/'.$relative))) {
                mkdir(dirname($target.'/'.$relative), 0777, true);
            }

            copy($file->getPathname(), $target.'/'.$relative);
            $count++;
        }

        return $count;
    }

    /** Keys that must be empty (or a placeholder) in a shipped .env template. */
    private const SECRET_KEYS = [
        'APP_KEY', 'OPENAI_API_KEY', 'CRON_TOKEN', 'XENDIT_SECRET_KEY', 'XENDIT_CALLBACK_TOKEN',
        'GOOGLE_CLIENT_SECRET', 'TURNSTILE_SECRET_KEY',
    ];

    /** Files that hold a Google service account key, wherever they are in the project. */
    private const KEY_FILE_PATTERN = '/(^|[\/_.-])(service[-_]?account|google[-_]?key|credentials?)[^\/]*\.json$|[-_.]google\.json$/i';

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
                || preg_match('/sk-[A-Za-z0-9_\-]{20,}/', $value)
                || str_contains($value, 'PRIVATE KEY')) {
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
     * Delete every release zip in $folder except those in $keep (the ones just built).
     * Only *.zip files are touched.
     *
     * @param  string|list<string>  $keep
     * @return int number of zips deleted
     */
    public function pruneOldReleases(string $folder, string|array $keep): int
    {
        $keep = array_map(fn (string $path) => realpath($path) ?: $path, (array) $keep);
        $deleted = 0;

        foreach (glob(rtrim($folder, '/\\').DIRECTORY_SEPARATOR.'*.zip') ?: [] as $zip) {
            if (! in_array(realpath($zip) ?: $zip, $keep, true) && @unlink($zip)) {
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
