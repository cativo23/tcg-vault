@component('mail.layouts.branded', [
    'preheader' => $typeLabel.' from '.($username ?? $userEmail),
    'footer' => 'Sent from the feedback form. Reply to answer the member directly.',
])
    <p style="margin:0 0 6px; font-size:12px; font-weight:700; letter-spacing:.1em; text-transform:uppercase; color:#6d6c62;">{{ $typeLabel }}</p>
    <p style="margin:0 0 4px;"><strong>{{ $username ?? $userEmail }}</strong> ({{ $userEmail }})</p>
    <p style="margin:0 0 16px; font-size:13px; color:#6d6c62;">Page: {{ $pagePath ?? 'unknown' }}</p>
    <p style="margin:0; padding:14px 16px; background:#f2efe6; border-radius:7px; white-space:pre-wrap;">{{ $body }}</p>
@endcomponent
