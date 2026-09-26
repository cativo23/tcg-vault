<?php

declare(strict_types=1);

namespace App\Support;

use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\Stacktrace;
use Throwable;

/**
 * Masks typed secrets in an error report before it goes to Bugsink.
 * Registered as `before_send`, which runs after the SDK has attached both
 * places a password can reach:
 *
 * - the request body, which the SDK attaches even with send_default_pii off;
 * - Livewire breadcrumbs, which record a component's public properties on
 *   every hydrate, so a password left in a form after a failed attempt
 *   rides along with the next request's report.
 *
 * Stack-frame arguments are dropped outright: a password passed to
 * Auth::attempt() or Livewire's update() would otherwise travel as frame
 * variables. The prod image's zend.exception_ignore_args already keeps
 * them out, and this keeps it true if that setting ever changes.
 */
final class SentryEventScrubber
{
    public static function beforeSend(Event $event, ?EventHint $hint = null): Event
    {
        $request = $event->getRequest();

        if ($request !== []) {
            $event->setRequest(SensitiveInput::scrub($request));
        }

        $event->setBreadcrumb(array_map(self::scrubBreadcrumb(...), $event->getBreadcrumbs()));

        foreach ($event->getExceptions() as $exception) {
            self::dropFrameVars($exception->getStacktrace());
        }
        self::dropFrameVars($event->getStacktrace());

        return $event;
    }

    private static function scrubBreadcrumb(Breadcrumb $breadcrumb): Breadcrumb
    {
        // Livewire Form objects become plain arrays here, so the scrubber
        // can walk them the same way the SDK will serialize them.
        // A value that can't be encoded costs its breadcrumb's metadata, not
        // the whole report (an exception here would drop the event).
        try {
            $metadata = json_decode((string) json_encode($breadcrumb->getMetadata(), JSON_PARTIAL_OUTPUT_ON_ERROR), true);
        } catch (Throwable) {
            $metadata = [];
        }

        return new Breadcrumb(
            $breadcrumb->getLevel(),
            $breadcrumb->getType(),
            $breadcrumb->getCategory(),
            $breadcrumb->getMessage(),
            SensitiveInput::scrub(is_array($metadata) ? $metadata : []),
            $breadcrumb->getTimestamp(),
        );
    }

    private static function dropFrameVars(?Stacktrace $stacktrace): void
    {
        foreach ($stacktrace?->getFrames() ?? [] as $frame) {
            $frame->setVars([]);
        }
    }
}
