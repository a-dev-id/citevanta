<?php

namespace App\Services\Deploy;

use Illuminate\Support\Carbon;

/**
 * Which release this installation is: the version and build date from
 * config/citevanta.php, and the id of the uploaded zip (release.json,
 * absent in local development). Shown in the admin footer and on
 * Admin > System to compare local and live at a glance.
 */
final class ReleaseInfo
{
    public static function version(): string
    {
        return (string) config('citevanta.version');
    }

    public static function buildDate(): string
    {
        return (string) config('citevanta.build_date');
    }

    /**
     * "v1.6.0 · 24 Nov 2026"
     */
    public static function label(): string
    {
        $date = self::buildDate();

        return 'v'.self::version().($date !== '' ? ' · '.Carbon::parse($date)->format('j M Y') : '');
    }

    /**
     * The id and time written into release.json when the zip was built.
     *
     * @return array{id: string, version: string, built_at: ?string}|null
     */
    public static function uploaded(): ?array
    {
        $file = base_path(StaleCacheGuard::RELEASE_FILE);
        $data = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;

        return is_array($data) && ($data['id'] ?? '') !== ''
            ? ['id' => (string) $data['id'], 'version' => (string) ($data['version'] ?? ''), 'built_at' => $data['built_at'] ?? null]
            : null;
    }

    /**
     * When the uploaded files were built (from release.json), e.g. "24 Nov 2026 07:21 UTC".
     */
    public static function builtAt(): ?string
    {
        $builtAt = self::uploaded()['built_at'] ?? null;

        try {
            return $builtAt ? Carbon::parse($builtAt)->utc()->format('j M Y H:i').' UTC' : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Does release.json (the uploaded files) say the same version as the
     * running config? false = a half-done upload (e.g. only one zip, or an old
     * cached config). null = nothing to compare (no release.json, or an old one
     * without a version).
     */
    public static function matchesConfig(): ?bool
    {
        $version = self::uploaded()['version'] ?? '';

        return $version === '' ? null : $version === self::version();
    }
}
