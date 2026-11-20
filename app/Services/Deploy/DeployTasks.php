<?php

namespace App\Services\Deploy;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Throwable;

/**
 * Deploy tasks for hosts without SSH, started from Admin > System.
 *
 * Each task runs on its own and reports success or its error; one failing
 * task does not stop the others. Public storage uses a symlink when the host
 * allows it, otherwise the /storage/{path} route serves the files.
 */
class DeployTasks
{
    public const STORAGE_SYMLINK = 'symlink';

    public const STORAGE_ROUTE = 'route';

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private const TASKS = [
        ['migrate', ['--force' => true]],
        ['config:cache', []],
        ['route:cache', []],
        ['view:cache', []],
        ['event:cache', []],
    ];

    /**
     * @return list<array{task: string, ok: bool, output: string}>
     */
    public function run(): array
    {
        $results = array_map(fn (array $task) => $this->call(...$task), self::TASKS);
        $results[] = $this->linkStorage();

        return $results;
    }

    /**
     * @return list<array{task: string, ok: bool, output: string}>
     */
    public function clearCaches(): array
    {
        return [$this->call('optimize:clear', [])];
    }

    /**
     * How public files (storage/app/public) are served right now.
     */
    public function storageMethod(): string
    {
        return file_exists(public_path('storage')) ? self::STORAGE_SYMLINK : self::STORAGE_ROUTE;
    }

    public function symlinksAllowed(): bool
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return function_exists('symlink') && ! in_array('symlink', $disabled, true);
    }

    /**
     * @return array{task: string, ok: bool, output: string}
     */
    private function linkStorage(): array
    {
        if ($this->storageMethod() === self::STORAGE_SYMLINK) {
            return ['task' => 'storage:link', 'ok' => true, 'output' => 'public/storage already exists.'];
        }

        if (! $this->symlinksAllowed()) {
            return ['task' => 'storage:link', 'ok' => true, 'output' => 'Symlinks are disabled on this host: public files are served through the /storage route instead.'];
        }

        $result = $this->call('storage:link', []);

        if (! $result['ok'] || $this->storageMethod() !== self::STORAGE_SYMLINK) {
            return ['task' => 'storage:link', 'ok' => true, 'output' => 'Could not create the symlink ('.$result['output'].'): public files are served through the /storage route instead.'];
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{task: string, ok: bool, output: string}
     */
    protected function call(string $command, array $arguments): array
    {
        try {
            $code = Artisan::call($command, $arguments);

            return ['task' => $command, 'ok' => $code === 0, 'output' => Str::limit(trim(Artisan::output()), 500) ?: 'Done.'];
        } catch (Throwable $e) {
            return ['task' => $command, 'ok' => false, 'output' => class_basename($e).': '.Str::limit($e->getMessage(), 300)];
        }
    }
}
