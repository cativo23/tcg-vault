<?php

declare(strict_types=1);

namespace App\Support;

use Sentry\Event;
use Sentry\EventHint;

/**
 * The Sentry SDK attaches the request body to every error report even with
 * send_default_pii off, so a Livewire request that fails mid-login would
 * carry the typed password to Bugsink. Registered as `before_send`, which
 * runs after the SDK has attached the request.
 */
final class SentryEventScrubber
{
    public static function beforeSend(Event $event, ?EventHint $hint = null): Event
    {
        $request = $event->getRequest();

        if ($request !== []) {
            $event->setRequest(SensitiveInput::scrub($request));
        }

        return $event;
    }
}
