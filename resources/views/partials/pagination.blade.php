@if ($paginator->hasPages())
    <nav class="pager" aria-label="Pagination">
        @if ($paginator->onFirstPage())
            <span class="pager-link" aria-disabled="true"><x-icon name="arrow" class="flip" />Previous</span>
        @else
            <a class="pager-link" href="{{ $paginator->previousPageUrl() }}" rel="prev"><x-icon name="arrow" class="flip" />Previous</a>
        @endif

        <span class="pager-pages">
            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="pager-gap">…</span>
                @else
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span class="pager-page" aria-current="page">{{ $page }}</span>
                        @else
                            <a class="pager-page" href="{{ $url }}">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach
        </span>
        <span class="pager-summary">Page {{ $paginator->currentPage() }} of {{ number_format($paginator->lastPage()) }}</span>

        @if ($paginator->hasMorePages())
            <a class="pager-link" href="{{ $paginator->nextPageUrl() }}" rel="next">Next<x-icon name="arrow" /></a>
        @else
            <span class="pager-link" aria-disabled="true">Next<x-icon name="arrow" /></span>
        @endif
    </nav>
@endif
