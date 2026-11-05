<?php

declare(strict_types=1);

namespace Tests\Support;

/** Small HTML probes for revamp pages. Not used by the app. */
final class RevampHtml
{
    /** The visible label of the bar link marked aria-current="page", if any. */
    public static function currentNav(string $html): ?string
    {
        preg_match('#<a[^>]*aria-current="page"[^>]*>\s*([A-Za-z]+)\s*</a>#', $html, $m);

        return $m[1] ?? null;
    }

    /** The collection summary line as visible text, whitespace collapsed. */
    public static function summary(string $html): string
    {
        return self::textOf('col-summary', $html);
    }

    /** The price note under the summary (attribution, other currencies) as visible text; '' when absent. */
    public static function note(string $html): string
    {
        return self::textOf('col-note', $html);
    }

    /** The visible text of the first <p>/<h1>-<h3> whose class attribute is exactly $class, whitespace collapsed; '' when absent. */
    public static function text(string $class, string $html): string
    {
        preg_match('#class="'.preg_quote($class, '#').'"[^>]*>(.*?)</(?:p|h1|h2|h3)>#s', $html, $m);

        return trim((string) preg_replace('/\s+/', ' ', strip_tags($m[1] ?? '')));
    }

    private static function textOf(string $class, string $html): string
    {
        preg_match('#<p class="'.$class.'">(.*?)</p>#s', $html, $m);

        return trim((string) preg_replace('/\s+/', ' ', strip_tags($m[1] ?? '')));
    }
}
