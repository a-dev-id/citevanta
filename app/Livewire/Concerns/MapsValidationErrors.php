<?php

namespace App\Livewire\Concerns;

use Illuminate\Validation\ValidationException;

/**
 * Services report errors under their own field names ("text", "name").
 * Components show them next to their own inputs ("rows.12.text", "newName").
 */
trait MapsValidationErrors
{
    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @param  string|array<string, string>  $map  prefix for every key, or key => component key
     * @return T|null null when validation failed (errors are added to the bag)
     */
    protected function withMappedErrors(callable $callback, string|array $map): mixed
    {
        try {
            return $callback();
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                $target = is_string($map) ? $map.$key : ($map[$key] ?? $key);
                $this->addError($target, $messages[0]);
            }

            return null;
        }
    }
}
