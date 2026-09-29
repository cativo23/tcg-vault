@component('mail.layouts.branded', [
    'preheader' => ($inviter ?? 'Someone').' invited you to tcg-vault. The link works until '.$expiresOn.'.',
    'actionUrl' => $url,
    'actionText' => 'Accept the invite',
    'footer' => 'You’re getting this because someone invited '.$email.' to tcg-vault.',
])
    <h1 style="margin:0 0 14px; font-size:22px; line-height:1.25; font-weight:800;">You’re invited to tcg-vault</h1>
    <p style="margin:0 0 12px;"><strong>{{ $inviter ?? 'The tcg-vault team' }}</strong> invited you to the private beta.</p>
    <p style="margin:0 0 12px;">tcg-vault tracks a Pokémon card collection: every card with its daily price, its value over time and your own photo of it.</p>
    <p style="margin:0; color:#6d6c62;">The link works until <strong style="color:#141412;">{{ $expiresOn }}</strong>. If you weren’t expecting this, ignore it: no account is created unless you use the link.</p>
@endcomponent
