@component('mail.layouts.branded-text', [
    'actionUrl' => $url,
    'actionText' => 'Accept the invite',
    'footer' => 'You’re getting this because someone invited '.$email.' to tcg-vault.',
])
You’re invited to tcg-vault

{!! $inviter ?? 'The tcg-vault team' !!} invited you to the private beta.

tcg-vault tracks a Pokémon card collection: every card with its daily price, its value over time and your own photo of it.

The link works until {!! $expiresOn !!}. If you weren’t expecting this, ignore it: no account is created unless you use the link.
@endcomponent
