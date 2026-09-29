{{--
    The one HTML frame every email uses. Email clients drop <style> blocks
    and CSS variables, so every rule is inline and the values are the
    design.md hex tokens: bone background, paper panel, ink text, one
    green hairline. The wordmark is text, not an image, and nothing loads
    from another server: no fonts, no images, no tracking.

    Slots: $preheader (inbox preview line), $slot (the body), and an
    optional button from $actionUrl / $actionText with the link repeated
    underneath for clients that break buttons.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>tcg-vault</title>
</head>
<body style="margin:0; padding:0; background:#f2efe6; color:#141412; font-family:-apple-system, 'Segoe UI', Helvetica, Arial, sans-serif; -webkit-text-size-adjust:100%;">
    @isset($preheader)
        <div style="display:none; max-height:0; overflow:hidden; opacity:0;">{{ $preheader }}</div>
    @endisset
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f2efe6;">
        <tr>
            <td align="center" style="padding:32px 16px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;">
                    <tr>
                        <td style="padding:0 4px 14px; font-size:15px; font-weight:800; letter-spacing:.14em; color:#141412;">
                            <a href="{{ url('/') }}" style="color:#141412; text-decoration:none;">TCG-VAULT</a>
                        </td>
                    </tr>
                    <tr>
                        <td style="height:2px; line-height:2px; font-size:0; background:#37d17f;">&nbsp;</td>
                    </tr>
                    <tr>
                        <td style="background:#fbf9f3; border:1px solid #ded9c9; border-top:0; padding:28px 28px 24px; font-size:15px; line-height:1.6; color:#141412;">
                            {{ $slot }}

                            @isset($actionUrl)
                                <table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0 8px;">
                                    <tr>
                                        <td style="border-radius:999px; background:#141412;">
                                            <a href="{{ $actionUrl }}" style="display:inline-block; padding:12px 26px; font-size:15px; font-weight:700; color:#f2efe6; text-decoration:none; border-radius:999px;">{{ $actionText }}</a>
                                        </td>
                                    </tr>
                                </table>
                                <p style="margin:16px 0 0; font-size:12px; line-height:1.5; color:#6d6c62;">
                                    If the button doesn’t work, paste this link into your browser:<br>
                                    <a href="{{ $actionUrl }}" style="color:#6d6c62; word-break:break-all;">{{ $actionUrl }}</a>
                                </p>
                            @endisset
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:18px 4px 0; font-size:12px; line-height:1.5; color:#6d6c62;">
                            {{ $footer ?? 'You’re getting this because of your tcg-vault account.' }}<br>
                            <a href="{{ url('/') }}" style="color:#6d6c62;">{{ parse_url(url('/'), PHP_URL_HOST) }}</a>
                            &nbsp;·&nbsp;
                            <a href="{{ route('privacy') }}" style="color:#6d6c62;">Privacy</a>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
