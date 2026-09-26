<?php

declare(strict_types=1);

namespace App\Telescope;

use App\Support\SensitiveInput;
use Laravel\Telescope\Watchers\RequestWatcher;
use Symfony\Component\HttpFoundation\Response;

/**
 * Telescope's stock watcher only hides top-level `password` fields.
 * Livewire requests carry typed secrets nested in the payload and in the
 * snapshots it sends back, so every array this watcher records — the
 * request payload, the session and a JSON response — goes through
 * SensitiveInput first.
 */
final class ScrubbingRequestWatcher extends RequestWatcher
{
    /**
     * @param  array<array-key, mixed>|string  $payload
     * @return array<array-key, mixed>|string
     */
    protected function payload($payload)
    {
        $payload = parent::payload($payload);

        return is_array($payload) ? SensitiveInput::scrub($payload) : $payload;
    }

    /**
     * @return array<array-key, mixed>|string
     */
    protected function response(Response $response)
    {
        $content = parent::response($response);

        return is_array($content) ? SensitiveInput::scrub($content) : $content;
    }
}
