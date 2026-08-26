{{--
    Simple (previous / next only) pagination, used by simplePaginate().
    Nothing in EvacTech calls simplePaginate() today, but the framework default
    for this view also relies on Tailwind's stock gray palette, which app.css
    removes. Shipping a matching override means a future simplePaginate() call
    cannot silently render unstyled.
--}}
@if ($paginator->hasPages())
    <nav class="pager" role="navigation" aria-label="Pagination Navigation">
        @if ($paginator->onFirstPage())
            <span class="pager-btn" aria-disabled="true" aria-label="Previous page, unavailable">Previous</span>
        @else
            <a class="pager-btn" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Go to previous page">Previous</a>
        @endif

        <span class="pager-summary">Page {{ $paginator->currentPage() }}</span>

        @if ($paginator->hasMorePages())
            <a class="pager-btn" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Go to next page">Next</a>
        @else
            <span class="pager-btn" aria-disabled="true" aria-label="Next page, unavailable">Next</span>
        @endif
    </nav>
@endif
