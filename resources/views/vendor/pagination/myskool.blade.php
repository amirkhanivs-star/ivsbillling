@if ($paginator->hasPages())
<nav class="myskool-pagination" role="navigation" aria-label="Pagination">
    <div class="myskool-pagination-info">
        Showing <strong>{{ $paginator->firstItem() ?? 0 }}</strong> to <strong>{{ $paginator->lastItem() ?? 0 }}</strong> of <strong>{{ number_format($paginator->total()) }}</strong> entries
    </div>
    <ul class="myskool-pagination-list">
        {{-- Previous Page Link --}}
        @if ($paginator->onFirstPage())
            <li class="disabled" aria-disabled="true" aria-label="Previous">
                <span class="myskool-page-btn">&lsaquo;</span>
            </li>
        @else
            <li>
                <a class="myskool-page-btn" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Previous">&lsaquo;</a>
            </li>
        @endif

        {{-- Pagination Elements --}}
        @foreach ($elements as $element)
            {{-- "Three Dots" Separator --}}
            @if (is_string($element))
                <li class="disabled" aria-disabled="true">
                    <span class="myskool-page-btn dots">{{ $element }}</span>
                </li>
            @endif

            {{-- Array Of Links --}}
            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <li class="active" aria-current="page">
                            <span class="myskool-page-btn active">{{ $page }}</span>
                        </li>
                    @else
                        <li>
                            <a class="myskool-page-btn" href="{{ $url }}">{{ $page }}</a>
                        </li>
                    @endif
                @endforeach
            @endif
        @endforeach

        {{-- Next Page Link --}}
        @if ($paginator->hasMorePages())
            <li>
                <a class="myskool-page-btn" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Next">&rsaquo;</a>
            </li>
        @else
            <li class="disabled" aria-disabled="true" aria-label="Next">
                <span class="myskool-page-btn">&rsaquo;</span>
            </li>
        @endif
    </ul>
</nav>
@else
<div class="myskool-pagination-single">
    <div class="myskool-pagination-info">
        Showing <strong>{{ $paginator->firstItem() ?? 0 }}</strong> to <strong>{{ $paginator->lastItem() ?? 0 }}</strong> of <strong>{{ number_format($paginator->total()) }}</strong> entries
    </div>
</div>
@endif
