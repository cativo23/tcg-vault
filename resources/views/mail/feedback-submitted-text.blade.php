{{-- text/plain: never rendered as HTML, so raw output shows the member's
     text exactly as typed instead of as HTML entities. --}}
{!! $typeLabel !!} from {!! $username ?? $userEmail !!} ({!! $userEmail !!})
Page: {!! $pagePath ?? 'unknown' !!}

{!! $body !!}
