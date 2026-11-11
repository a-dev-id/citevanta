<?php

namespace App\Http\Middleware;

use App\Services\MonitoringMessages;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class SanitizeMonitoringErrors
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            return $next($request);
        } catch (ValidationException $exception) {
            $messages = [];
            foreach ($exception->errors() as $key => $errors) {
                foreach ($errors as $error) {
                    $public = MonitoringMessages::publicText($error);
                    $messages[$public === $error ? $key : 'service'][] = $public;
                }
            }
            throw ValidationException::withMessages($messages);
        }
    }
}
