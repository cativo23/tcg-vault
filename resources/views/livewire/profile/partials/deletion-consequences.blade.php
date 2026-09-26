{{ __('This permanently deletes your whole collection, including every card’s notes and photos.') }}
@if ($username = auth()->user()?->username)
    {{ __('Your page at /:username goes offline and the username becomes available to anyone.', ['username' => $username]) }}
@endif
{{ __('This can’t be undone.') }}
