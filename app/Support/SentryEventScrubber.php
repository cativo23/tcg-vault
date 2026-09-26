<?php

declare(strict_types=1);

namespace App\Support;

use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;

/**
 * Masks typed secrets in an error report before it goes to Bugsink.
 * Registered as `before_send`, which runs after the SDK has attached both
 * places a password can reach:
 *
 * - the request body, which the SDK attaches even with send_default_pii off;
 * - Livewire breadcrumbs, which record a component's public properties on
 *   every hydrate, so a password left in a form after a failed attempt
 *   rides along with the next request's report.
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

        return $event;
    }

    private static function scrubBreadcrumb(Breadcrumb $breadcrumb): Breadcrumb
    {
        // Livewire Form objects become plain arrays here, so the scrubber
        // can walk them the same way the SDK will serialize them.
        $metadata = json_decode((string) json_encode($breadcrumb->getMetadata(), JSON_PARTIAL_OUTPUT_ON_ERROR), true);

        return new Breadcrumb(
            $breadcrumb->getLevel(),
            $breadcrumb->getType(),
            $breadcrumb->getCategory(),
            $breadcrumb->getMessage(),
            SensitiveInput::scrub(is_array($metadata) ? $metadata : []),
            $breadcrumb->getTimestamp(),
        );
    }
}
