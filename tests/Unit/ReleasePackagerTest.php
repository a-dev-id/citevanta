<?php

namespace Tests\Unit;

use App\Services\Deploy\ReleasePackager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReleasePackagerTest extends TestCase
{
    public static function included(): array
    {
        return [
            ['app/Models/Hotel.php'], ['artisan'], ['composer.json'], ['composer.lock'], ['config/citevanta.php'],
            ['public/index.php'], ['public/.htaccess'], ['public/build/manifest.json'], ['routes/web.php'],
            ['resources/views/welcome.blade.php'], ['database/migrations/2026_11_18_075917_create_teams_table.php'],
            ['.env.example'], ['.env.production.example'], ['docs/DEPLOY.md'],
            ['storage/logs/.gitignore'], ['storage/framework/views/.gitignore'], ['bootstrap/cache/.gitignore'],
        ];
    }

    #[DataProvider('included')]
    public function test_app_files_are_shipped(string $path): void
    {
        $this->assertTrue(ReleasePackager::shouldInclude($path), $path);
    }

    public static function excluded(): array
    {
        return [
            ['.env'], ['.env.backup'], ['.env.production'], ['node_modules/vite/package.json'], ['tests/TestCase.php'],
            ['.git/config'], ['vendor/autoload.php'], ['storage/logs/laravel.log'], ['storage/framework/sessions/abc'],
            ['storage/framework/views/x.php'], ['storage/app/private/file.txt'], ['bootstrap/cache/config.php'],
            ['bootstrap/cache/packages.php'], ['public/hot'], ['public/storage/x.jpg'], ['.claude/settings.json'],
            ['CLAUDE.md'], ['.mcp.json'], ['releases/citevanta-old.zip'], ['database/database.sqlite'],
        ];
    }

    #[DataProvider('excluded')]
    public function test_secrets_tests_and_local_files_are_not_shipped(string $path): void
    {
        $this->assertFalse(ReleasePackager::shouldInclude($path), $path);
    }

    public function test_placeholder_templates_contain_no_secrets(): void
    {
        $template = "APP_KEY=\nDB_PASSWORD=CHANGE-ME\nMAIL_PASSWORD=null\nREDIS_PASSWORD=null\nOPENAI_API_KEY=\nCRON_TOKEN=\nCITEVANTA_SCAN_MODEL=gpt-6.1-terra\n";

        $this->assertSame([], ReleasePackager::secretsIn($template));
    }

    public function test_real_secrets_in_a_template_are_detected_by_key_name(): void
    {
        $template = implode("\n", [
            'APP_KEY=base64:YWJjZGVmZ2hpamtsbW5vcHFyc3R1dnd4eXo=',
            'DB_PASSWORD=hunter2',
            'MAIL_PASSWORD="secret pass"',
            'OPENAI_API_KEY=sk-proj-abcdefghijklmnopqrstuvwxyz0123',
            'CRON_TOKEN=6fc3be1cfa12c9c095fad24da60e4b6d07f3c41eac51d209',
            'SOME_NOTE=sk-abcdefghijklmnopqrstuvwxyz',
            'APP_NAME=CiteVanta',
            'XENDIT_SECRET_KEY=xnd_production_abc123',
            'XENDIT_CALLBACK_TOKEN=tok123',
            'GOOGLE_CLIENT_SECRET=GOCSPX-abc',
            'TURNSTILE_SECRET_KEY=0x4AAAAAAA',
            'XENDIT_MODE=live',
        ]);

        $this->assertSame(
            ['APP_KEY', 'DB_PASSWORD', 'MAIL_PASSWORD', 'OPENAI_API_KEY', 'CRON_TOKEN', 'SOME_NOTE', 'XENDIT_SECRET_KEY', 'XENDIT_CALLBACK_TOKEN', 'GOOGLE_CLIENT_SECRET', 'TURNSTILE_SECRET_KEY'],
            ReleasePackager::secretsIn($template),
        );
    }

    public function test_old_release_zips_are_removed_and_only_the_new_one_and_other_files_stay(): void
    {
        $folder = sys_get_temp_dir().DIRECTORY_SEPARATOR.'citevanta-prune-'.uniqid();
        mkdir($folder);
        foreach (['citevanta-2026-11-01-100000.zip', 'citevanta-2026-11-10-100000.zip', 'citevanta-2026-11-20-100000.zip', 'notes.txt'] as $file) {
            file_put_contents($folder.DIRECTORY_SEPARATOR.$file, 'x');
        }

        $deleted = (new ReleasePackager)->pruneOldReleases($folder, $folder.DIRECTORY_SEPARATOR.'citevanta-2026-11-20-100000.zip');

        $remaining = array_values(array_diff(scandir($folder), ['.', '..']));
        sort($remaining);
        array_map('unlink', array_map(fn ($f) => $folder.DIRECTORY_SEPARATOR.$f, $remaining));
        rmdir($folder);

        $this->assertSame(2, $deleted);
        $this->assertSame(['citevanta-2026-11-20-100000.zip', 'notes.txt'], $remaining);
    }

    public function test_windows_paths_are_normalised(): void
    {
        $this->assertFalse(ReleasePackager::shouldInclude('node_modules\\vite\\index.js'));
        $this->assertTrue(ReleasePackager::shouldInclude('app\\Models\\Hotel.php'));
    }
}
