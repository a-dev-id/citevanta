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

#[Signature('citevanta:package {--skip-build : Do not run npm run build first (use the existing public/build)}')]
#[Description('Build a release zip (with vendor and public/build) for hosts without SSH')]
class PackageReleaseCommand extends Command
{
    public function handle(ReleasePackager $packager): int
    {
        $base = base_path();
        $temp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'citevanta-release-'.now()->format('YmdHis');
        $zipPath = $base.DIRECTORY_SEPARATOR.'releases'.DIRECTORY_SEPARATOR.'citevanta-'.now()->format('Y-m-d-His').'.zip';

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
            $this->info('1/4 Building assets (npm run build)…');
            if (! $this->runStep('npm run build', $base)) {
                return self::FAILURE;
            }
        }

        if (! File::exists(public_path('build/manifest.json'))) {
            $this->error('public/build/manifest.json is missing. Run npm run build first.');

            return self::FAILURE;
        }

        try {
            $this->info('2/4 Copying project files (without .env, tests, node_modules, logs)…');
            $copied = $packager->copyProject($base, $temp);
            // Lets the server clear caches built for the previous release on its first request (StaleCacheGuard).
            File::put($temp.DIRECTORY_SEPARATOR.StaleCacheGuard::RELEASE_FILE, StaleCacheGuard::releaseFile(now()->format('YmdHis').'-'.bin2hex(random_bytes(4))));
            $this->line("    {$copied} files copied to a temporary folder.");

            $this->info('3/4 Installing production packages (composer install --no-dev --optimize-autoloader)…');
            if (! $this->runStep('composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist --no-progress --no-scripts', $temp)) {
                return self::FAILURE;
            }

            $this->info('4/4 Creating the zip…');
            $packager->zip($temp, $zipPath);
        } finally {
            File::deleteDirectory($temp);
        }

        // Only after a successful build: a failed build keeps the previous zip.
        $deleted = $packager->pruneOldReleases(dirname($zipPath), $zipPath);

        $this->newLine();
        if ($deleted > 0) {
            $this->line("Removed {$deleted} older release ".($deleted === 1 ? 'zip' : 'zips').' from releases/.');
        }
        $this->info('Release ready:');
        $this->line('  '.$zipPath);
        $this->line('  '.Number::fileSize(File::size($zipPath), precision: 1));
        $this->line('  Upload it and extract it into the app folder on the server (see docs/DEPLOY.md).');

        return self::SUCCESS;
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
