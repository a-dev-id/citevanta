<?php

namespace Tests\Unit;

use App\Services\Deploy\StaleCacheGuard;
use PHPUnit\Framework\TestCase;

class StaleCacheGuardTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'citevanta-guard-'.uniqid();
        mkdir($this->base.'/bootstrap/cache', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->base.'/bootstrap/cache/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        @unlink($this->base.'/release.json');
        rmdir($this->base.'/bootstrap/cache');
        rmdir($this->base.'/bootstrap');
        rmdir($this->base);
        parent::tearDown();
    }

    private function cacheFiles(): array
    {
        $files = array_map('basename', glob($this->base.'/bootstrap/cache/{,.}*', GLOB_BRACE) ?: []);
        $files = array_values(array_filter($files, fn ($f) => ! in_array($f, ['.', '..'], true)));
        sort($files);

        return $files;
    }

    private function writeOldCaches(): void
    {
        foreach (['config.php', 'routes-v7.php', 'events.php', 'services.php', 'packages.php', '.gitignore'] as $file) {
            file_put_contents($this->base.'/bootstrap/cache/'.$file, '<?php return [];');
        }
    }

    public function test_a_new_release_clears_the_old_caches_once(): void
    {
        $this->writeOldCaches();
        file_put_contents($this->base.'/release.json', StaleCacheGuard::releaseFile('r2'));

        $this->assertTrue(StaleCacheGuard::clearIfNewRelease($this->base));
        $this->assertSame(['.gitignore', 'release-id'], $this->cacheFiles());

        // Caches rebuilt by "Run deploy tasks" for this release are kept from now on.
        $this->writeOldCaches();
        $this->assertFalse(StaleCacheGuard::clearIfNewRelease($this->base));
        $this->assertContains('routes-v7.php', $this->cacheFiles());
    }

    public function test_without_a_release_file_nothing_is_touched(): void
    {
        $this->writeOldCaches();

        $this->assertFalse(StaleCacheGuard::clearIfNewRelease($this->base));
        $this->assertContains('config.php', $this->cacheFiles());
    }
}
