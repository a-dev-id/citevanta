<?php

namespace App\Http\Controllers;

use App\Services\Cron\CronRunner;
use App\Services\Cron\SystemHealth;
use Illuminate\Http\JsonResponse;

/**
 * Fallback URL cron (when a command cron is not possible). Runs outside the
 * web middleware: no session, no cookies, no CSRF. A wrong, missing or
 * disabled (empty CRON_TOKEN) token gives a plain 404.
 */
class CronController extends Controller
{
    public function run(string $token, CronRunner $runner): JsonResponse
    {
        $this->authorizeToken($token);

        return response()->json($runner->run()->toArray());
    }

    public function health(string $token, SystemHealth $health): JsonResponse
    {
        $this->authorizeToken($token);

        return response()->json($health->collect());
    }

    private function authorizeToken(string $token): void
    {
        $expected = (string) config('citevanta.cron.token');

        abort_if($expected === '' || ! hash_equals($expected, $token), 404);
    }
}
