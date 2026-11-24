<?php

namespace App\Services\Deploy;

/**
 * Runs at the very start of every request and command (bootstrap/app.php),
 * before Laravel loads its cached config and routes.
 *
 * A release zip carries release.json with a unique id. Extracting a new zip
 * over the old app cannot delete the old caches in bootstrap/cache, and those
 * caches describe the old code (e.g. routes that did not exist yet), so the
 * first request after an upload deletes them and remembers the release id.
 * "Run deploy tasks" builds fresh caches afterwards.
 *
 * Plain PHP only: no framework services exist yet at this point.
 */
final class StaleCacheGuard
{
    public const RELEASE_FILE = 'release.json';

    public const MARKER_FILE = 'bootstrap/cache/release-id';

    /** Cache files built for a specific version of the code. */
    private const CACHE_PATTERNS = ['config.php', 'routes*.php', 'events.php', 'services.php', 'packages.php'];

    /**
     * @return bool true when caches were cleared for a new release
     */
    public static function clearIfNewRelease(string $basePath): bool
    {
        $release = $basePath.DIRECTORY_SEPARATOR.self::RELEASE_FILE;

        if (! is_file($release)) {
            return false; // local development: no release file
        }

        $id = (string) (json_decode((string) @file_get_contents($release), true)['id'] ?? '');
        $marker = $basePath.DIRECTORY_SEPARATOR.self::MARKER_FILE;

        if ($id === '' || (is_file($marker) && trim((string) @file_get_contents($marker)) === $id)) {
            return false;
        }

        $cacheDir = dirname($marker);
        foreach (self::CACHE_PATTERNS as $pattern) {
            foreach (glob($cacheDir.DIRECTORY_SEPARATOR.$pattern) ?: [] as $file) {
                @unlink($file);
            }
        }

        @file_put_contents($marker, $id);

        return true;
    }

    /**
     * Contents of release.json for a new release zip.
     */
    public static function releaseFile(string $id, string $version = '', string $buildDate = ''): string
    {
        return (string) json_encode(
            ['id' => $id, 'version' => $version, 'build_date' => $buildDate, 'built_at' => gmdate('c')],
            JSON_PRETTY_PRINT,
        );
    }
}
