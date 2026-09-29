{{-- text/plain: never rendered as HTML, so raw output shows the member's
     text exactly as typed instead of as HTML entities. --}}
@component('mail.layouts.branded-text', [
    'footer' => 'Sent from the feedback form. Reply to answer the member directly.',
])
{!! $typeLabel !!} from {!! $username ?? $userEmail !!} ({!! $userEmail !!})
Page: {!! $pagePath ?? 'unknown' !!}

{!! $body !!}
@endcomponent
