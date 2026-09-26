{{ __('This permanently deletes your whole collection, including every card’s notes and photos.') }}
@if ($username = auth()->user()?->username)
    {{ __('Your page at /:username goes offline and the username becomes available to anyone.', ['username' => $username]) }}
@endif
{{ __('This can’t be undone.') }}
@can('use-collection')
    <a href="{{ route('admin.collection.export') }}" download style="color: var(--ink); text-decoration: underline">{{ __('Download your collection as CSV') }}</a>
    {{ __('first if you want to keep a copy.') }}
@endcan
