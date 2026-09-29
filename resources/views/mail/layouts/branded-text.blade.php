{{-- text/plain twin of branded.blade.php. Never rendered as HTML, so values
     are output raw and appear exactly as written. --}}
TCG-VAULT
=========

{!! $slot !!}
@isset($actionUrl)

{!! $actionText !!}: {!! $actionUrl !!}
@endisset

--
{!! $footer ?? 'You’re getting this because of your tcg-vault account.' !!}
tcg-vault · {!! url('/') !!} · Privacy: {!! route('privacy') !!}
