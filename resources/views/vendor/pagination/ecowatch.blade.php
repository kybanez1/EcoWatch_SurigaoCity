@if($paginator->hasPages())
  <nav class="pagination" role="navigation" aria-label="Pagination">
    @if($paginator->onFirstPage())
      <span class="page-link disabled">&laquo; Previous</span>
    @else
      <a class="page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev">&laquo; Previous</a>
    @endif

    @foreach($elements as $element)
      @if(is_string($element))
        <span class="page-link disabled">{{ $element }}</span>
      @endif
      @if(is_array($element))
        @foreach($element as $pageNumber => $pageUrl)
          @if($pageNumber == $paginator->currentPage())
            <span class="page-link current" aria-current="page">{{ $pageNumber }}</span>
          @else
            <a class="page-link" href="{{ $pageUrl }}">{{ $pageNumber }}</a>
          @endif
        @endforeach
      @endif
    @endforeach

    @if($paginator->hasMorePages())
      <a class="page-link" href="{{ $paginator->nextPageUrl() }}" rel="next">Next &raquo;</a>
    @else
      <span class="page-link disabled">Next &raquo;</span>
    @endif
  </nav>
@endif
