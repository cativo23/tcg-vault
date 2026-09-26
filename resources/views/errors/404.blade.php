@php
    // A stale or mistyped gallery link is a normal, expected 404 on a
    // public site — a guest hitting it may have no account to log into
    // at all. An authenticated user's stale link is a different case
    // (their own bookmark going bad), so send them somewhere real
    // instead of asking them to log in again.
    $authenticated = auth()->check();
@endphp
<x-error-page
    code="404"
    :title="__('Page not found')"
    :message="__('The page you\'re looking for doesn\'t exist or has moved.')"
    :link-route="$authenticated ? 'admin.collection.index' : 'home'"
    :link-label="$authenticated ? __('Back to your collection') : __('Go home')"
/>
