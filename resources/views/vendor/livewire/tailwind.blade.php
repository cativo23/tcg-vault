{{--
    Overrides Livewire's own pagination view (package default at
    vendor/livewire/livewire/src/Features/SupportPagination/views/tailwind.blade.php)
    — every paginated Livewire component in this app (CollectionItems,
    InviteManager) renders through Livewire's 'livewire::tailwind' view,
    NOT Laravel's own 'pagination::tailwind', because WithPagination
    swaps Paginator::$defaultView for the duration of the render. That
    package default hardcodes Tailwind grays (bg-white, text-gray-*,
    dark:*) that don't route through this app's own
    --ink/--paper/--hair/--muted tokens — the exact class of bug
    DarkModeTest exists to catch, invisible until a list actually has a
    second page. Livewire resolves this file first, automatically, for
    every $paginator->links() call in a Livewire component.
--}}
@if ($paginator->hasPages())
    <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}">
        <div class="flex gap-2 items-center justify-between sm:hidden">
            @if ($paginator->onFirstPage())
                <span class="nw-btn-secondary" style="opacity: .5; cursor: not-allowed">{!! __('pagination.previous') !!}</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="nw-btn-secondary">{!! __('pagination.previous') !!}</a>
            @endif

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="nw-btn-secondary">{!! __('pagination.next') !!}</a>
            @else
                <span class="nw-btn-secondary" style="opacity: .5; cursor: not-allowed">{!! __('pagination.next') !!}</span>
            @endif
        </div>

        <div class="hidden sm:flex-1 sm:flex sm:gap-2 sm:items-center sm:justify-between">
            <p class="text-sm" style="color: var(--muted)">
                {!! __('Showing') !!}
                @if ($paginator->firstItem())
                    <span class="font-medium" style="color: var(--ink)">{{ $paginator->firstItem() }}</span>
                    {!! __('to') !!}
                    <span class="font-medium" style="color: var(--ink)">{{ $paginator->lastItem() }}</span>
                @else
                    {{ $paginator->count() }}
                @endif
                {!! __('of') !!}
                <span class="font-medium" style="color: var(--ink)">{{ $paginator->total() }}</span>
                {!! __('results') !!}
            </p>

            <span class="inline-flex rtl:flex-row-reverse" style="border-radius: 8px; box-shadow: 0 0 0 1px var(--hair)">
                @if ($paginator->onFirstPage())
                    <span aria-disabled="true" aria-label="{{ __('pagination.previous') }}" class="inline-flex items-center px-2 py-2 text-sm rounded-l-md" style="color: var(--muted); background: var(--bone-2); cursor: not-allowed" aria-hidden="true">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
                    </span>
                @else
                    <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="inline-flex items-center px-2 py-2 text-sm rounded-l-md" style="color: var(--ink); background: var(--paper)" aria-label="{{ __('pagination.previous') }}">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
                    </a>
                @endif

                @foreach ($elements as $element)
                    @if (is_string($element))
                        <span aria-disabled="true">
                            <span class="inline-flex items-center px-4 py-2 -ml-px text-sm" style="color: var(--muted); background: var(--paper); cursor: default">{{ $element }}</span>
                        </span>
                    @endif

                    @if (is_array($element))
                        @foreach ($element as $page => $url)
                            @if ($page == $paginator->currentPage())
                                <span aria-current="page">
                                    <span class="inline-flex items-center px-4 py-2 -ml-px text-sm font-medium" style="color: var(--bone); background: var(--ink); cursor: default">{{ $page }}</span>
                                </span>
                            @else
                                <a href="{{ $url }}" class="inline-flex items-center px-4 py-2 -ml-px text-sm" style="color: var(--ink); background: var(--paper)" aria-label="{{ __('Go to page :page', ['page' => $page]) }}">
                                    {{ $page }}
                                </a>
                            @endif
                        @endforeach
                    @endif
                @endforeach

                @if ($paginator->hasMorePages())
                    <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="inline-flex items-center px-2 py-2 -ml-px text-sm rounded-r-md" style="color: var(--ink); background: var(--paper)" aria-label="{{ __('pagination.next') }}">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" /></svg>
                    </a>
                @else
                    <span aria-disabled="true" aria-label="{{ __('pagination.next') }}" class="inline-flex items-center px-2 py-2 -ml-px text-sm rounded-r-md" style="color: var(--muted); background: var(--bone-2); cursor: not-allowed" aria-hidden="true">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" /></svg>
                    </span>
                @endif
            </span>
        </div>
    </nav>
@endif
