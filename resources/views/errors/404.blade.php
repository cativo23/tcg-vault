@php
    // A stale or mistyped gallery link is a normal, expected 404 on a
    // public site — a guest hitting it may have no account to log into
    // at all. An authenticated user's stale link is a different case
    // (their own bookmark going bad), so send them somewhere real
    // instead of asking them to log in again — but only somewhere they
    // can actually reach: admin.collection.index gates on use-collection,
    // not just auth, so an authenticated user without it would otherwise
    // bounce straight from this 404 into a 403.
    $canUseCollection = auth()->user()?->can('use-collection') ?? false;
@endphp
<x-error-page
    code="404"
    :title="__('Page not found')"
    :message="__('The page you\'re looking for doesn\'t exist or has moved.')"
    :link-route="$canUseCollection ? 'admin.collection.index' : 'home'"
    :link-label="$canUseCollection ? __('Back to your collection') : __('Go home')"
/>
