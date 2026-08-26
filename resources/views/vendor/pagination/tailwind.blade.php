{{--
    EvacTech pagination.

    Overrides the framework's pagination::tailwind view. Placing the file here
    is enough -- Laravel resolves resources/views/vendor/pagination first, so no
    PHP change and no Paginator::defaultView() call is needed.

    Two reasons this is not the stock view:
      * the stock numbered links are ~28px tall, well under the 44px tap target
        this project holds itself to
      * they use Tailwind's default gray palette, which app.css deliberately
        removes, so they would render unstyled

    Below 640px the numbers are replaced by a plain "Page 2 of 7" between two
    large Previous / Next buttons. A row of small numbers is a poor target on a
    phone, and staff paging through a household list need a big obvious button
    far more than they need to jump to page 5.
--}}
@if ($paginator->hasPages())
    <nav class="pager" role="navigation" aria-label="Pagination Navigation">

        {{-- Previous --}}
        @if ($paginator->onFirstPage())
            <span class="pager-btn" aria-disabled="true" aria-label="Previous page, unavailable">Previous</span>
        @else
            <a class="pager-btn" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Go to previous page">Previous</a>
        @endif

        {{-- Phone: a plain statement of position. --}}
        <span class="pager-summary pager-mobile-label">
            Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}
        </span>

        {{-- Tablet and up: numbered links. --}}
        <span class="pager-numbers">
            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="pager-btn" aria-disabled="true">{{ $element }}</span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span class="pager-btn is-current" aria-current="page">{{ $page }}</span>
                        @else
                            <a class="pager-btn" href="{{ $url }}" aria-label="Go to page {{ $page }}">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach
        </span>

        {{-- Next --}}
        @if ($paginator->hasMorePages())
            <a class="pager-btn" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Go to next page">Next</a>
        @else
            <span class="pager-btn" aria-disabled="true" aria-label="Next page, unavailable">Next</span>
        @endif
    </nav>
@endif
