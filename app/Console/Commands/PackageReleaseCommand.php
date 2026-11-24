<?php

namespace App\Console\Commands;

use App\Services\Deploy\ReleasePackager;
use App\Services\Deploy\StaleCacheGuard;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Number;
use ZipArchive;

/**
 * Two zips, each extracted inside its own target folder (nothing is nested,
 * so a wrong extract folder is easy to spot):
 *
 *   citevanta-app-{version}-{timestamp}.zip     extract into /home/USER/citevanta
 *       the project, vendor without dev packages, public/build, release.json, RELEASE.md
 *   citevanta-public-{version}-{timestamp}.zip  extract into the site's document root
 *       the contents of public/, but not the server's own index.php and .htaccess
 *
 * Every run writes a new release.json (id = date, time and random suffix, plus
 * version and built_at) and checks that the finished app zip contains it.
 * Composer runs in a temporary copy, so local dev packages are never touched.
 */
#[Signature('citevanta:package {--skip-build : Do not run npm run build first (use the existing public/build)}')]
#[Description('Build the two release zips (app and public) in releases/')]
class PackageReleaseCommand extends Command
{
    public function handle(ReleasePackager $packager): int
    {
        $base = base_path();
        $version = (string) config('citevanta.version');
        $date = (string) config('citevanta.build_date');
        $stamp = now()->format('Ymd-His');
        $id = now()->format('YmdHis').'-'.bin2hex(random_bytes(4));
        $temp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'citevanta-release-'.now()->format('YmdHis');
        $releases = $base.DIRECTORY_SEPARATOR.'releases'.DIRECTORY_SEPARATOR;
        $appZip = $releases."citevanta-app-{$version}-{$stamp}.zip";
        $publicZip = $releases."citevanta-public-{$version}-{$stamp}.zip";

        if ($version === '' || $date === '') {
            $this->error('Set citevanta.version and citevanta.build_date in config/citevanta.php first.');

            return self::FAILURE;
        }

        if ($date !== now()->toDateString()) {
            $this->warn("citevanta.build_date is {$date}, today is ".now()->toDateString().'. Update it if this is a new release.');
        }

        if (! File::exists($base.DIRECTORY_SEPARATOR.'RELEASE.md')) {
            $this->error('RELEASE.md is missing in the project root. It ships in the app zip (what changed, how to upload).');

            return self::FAILURE;
        }

        // The env templates ship in the zip: they must not contain real secrets.
        foreach (['.env.example', '.env.production.example'] as $template) {
            $path = $base.DIRECTORY_SEPARATOR.$template;
            $secrets = File::exists($path) ? ReleasePackager::secretsIn(File::get($path)) : [];

            if ($secrets !== []) {
                $this->error("{$template} contains real values for: ".implode(', ', $secrets).'.');
                $this->line('This file is a template that ships in the zip (and in git). Put real values only in .env on the server,');
                $this->line('and put placeholders back in the template (empty, or CHANGE-ME for passwords). No zip was built.');

                return self::FAILURE;
            }
        }

        if (! $this->option('skip-build')) {
            $this->info('1/5 Building assets (npm run build)...');
            if (! $this->runStep('npm run build', $base)) {
                return self::FAILURE;
            }
        }

        if (! File::exists(public_path('build/manifest.json'))) {
            $this->error('public/build/manifest.json is missing. Run npm run build first.');

            return self::FAILURE;
        }

        try {
            $this->info('2/5 Copying project files (without .env, tests, node_modules, logs, storage content)...');
            $app = $temp.DIRECTORY_SEPARATOR.'app';
            $copied = $packager->copyProject($base, $app);
            File::put($app.DIRECTORY_SEPARATOR.StaleCacheGuard::RELEASE_FILE, StaleCacheGuard::releaseFile($id, $version, $date));
            File::copy($base.DIRECTORY_SEPARATOR.'RELEASE.md', $app.DIRECTORY_SEPARATOR.'RELEASE.md');
            $this->line("    {$copied} files.");

            $this->info('3/5 Installing production packages (composer install --no-dev --optimize-autoloader)...');
            if (! $this->runStep('composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist --no-progress --no-scripts', $app)) {
                return self::FAILURE;
            }

            $this->info('4/5 Copying public/ (without index.php and .htaccess)...');
            $public = $temp.DIRECTORY_SEPARATOR.'public';
            $this->line('    '.$packager->copyPublic(public_path(), $public).' files.');

            $this->info('5/5 Creating the zips...');
            $packager->zip($app, $appZip);
            $packager->zip($public, $publicZip);
        } finally {
            File::deleteDirectory($temp);
        }

        $inside = $this->releaseIdInside($appZip);
        if ($inside !== $id) {
            File::delete([$appZip, $publicZip]);
            $this->error('The app zip does not contain the new release.json (found: '.($inside ?: 'none').'). The zips were removed.');

            return self::FAILURE;
        }

        // Only after a successful build: a failed build keeps the previous zips.
        $deleted = $packager->pruneOldReleases(dirname($appZip), [$appZip, $publicZip]);

        $this->newLine();
        if ($deleted > 0) {
            $this->line("Removed {$deleted} older release ".($deleted === 1 ? 'zip' : 'zips').' from releases/.');
        }
        $this->info("Release {$version} ({$date}) ready. Release id: {$id}");
        $this->line('  App    '.basename($appZip).'  '.Number::fileSize(File::size($appZip), precision: 1));
        $this->line('         open /home/USER/citevanta in File Manager, then Upload and Extract (overwrite on).');
        $this->line('  Public '.basename($publicZip).'  '.Number::fileSize(File::size($publicZip), precision: 1));
        $this->line('         open the site document root (public_html/your-domain), then Upload and Extract (overwrite on).');
        $this->line("  Verify: citevanta/release.json on the server must show \"id\": \"{$id}\". Steps: RELEASE.md.");

        return self::SUCCESS;
    }

    private function releaseIdInside(string $zipPath): string
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            return '';
        }

        $json = $zip->getFromName(StaleCacheGuard::RELEASE_FILE);
        $zip->close();

        return (string) (json_decode((string) $json, true)['id'] ?? '');
    }

    private function runStep(string $command, string $path): bool
    {
        $result = Process::path($path)->timeout(900)->run($command, function (string $type, string $output) {
            if ($this->output->isVerbose()) {
                $this->output->write($output);
            }
        });

        if ($result->failed()) {
            $this->error("\"{$command}\" failed:");
            $this->line(trim($result->errorOutput()) ?: trim($result->output()));

            return false;
        }

        return true;
    }
}
