@component('mail.layouts.branded', [
    'preheader' => 'Reset your tcg-vault password. The link works for '.$minutes.' minutes.',
    'actionUrl' => $url,
    'actionText' => 'Choose a new password',
    'footer' => 'You’re getting this because a password reset was asked for on your tcg-vault account.',
])
    <h1 style="margin:0 0 14px; font-size:22px; line-height:1.25; font-weight:800;">Reset your password</h1>
    <p style="margin:0 0 12px;">Someone asked to reset the password for your tcg-vault account. Use the button below to choose a new one.</p>
    <p style="margin:0; color:#6d6c62;">The link works for <strong style="color:#141412;">{{ $minutes }} minutes</strong>. If you didn’t ask for this, ignore it: your password stays the same.</p>
@endcomponent
