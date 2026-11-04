   <div id="searchLoaderOverlay" class="search-loader-overlay d-none">
       <div class="loader-spinner"></div>
   </div>
   <div class="fs-14 fw-medium text-center text-body-light">
       @if ($recent)
           {{ translate('Recent_Search') }}
       @else
           {{ translate('Search_Result') }}
       @endif
   </div>
   <div class="search-list d-flex flex-column">
       @if (count($result) > 0)
           <?php
           function highlightKeyword($text, $keyword)
           {
               $escapedKeyword = preg_quote($keyword, '/');
               return preg_replace("/($escapedKeyword)/i", '<mark>$1</mark>', $text);
           }
           ?>
           @foreach ($result as $groupKey => $resultItem)
               <h6 class="fs-14 fw-bold text-body-light mt-1 mb-1">
                   {{ ucfirst(translate($groupKey)) }}
               </h6>
               @foreach ($resultItem as $key => $item)
                   @php
                       $title = $item['page_title_value'] ?? 'N/A';
                       $title = ucfirst(str_replace('_', ' ', removeSpecialCharacters(str_replace("\'", "'", $title))));
                       $uri = $item['uri'] ?? '';
                       $image = $item['image'] ?? null;
                       $uniqueId = 'item_' . $groupKey . '_' . $key;

                       $highlightedTitle = $keyword ? highlightKeyword($title, $keyword) : $title;
                       $highlightedUri = $keyword ? highlightKeyword($uri, $keyword) : $uri;
                   @endphp

                   <form action="{{ route('admin.advanced-search-recent') }}" method="POST">
                       @csrf

                       <input hidden name="routeName" value="{{ $item['page_title_value'] }}">
                       <input hidden name="routeUri" value="{{ $item['uri'] }}">
                       <input hidden name="routeFullUrl" value="{{ url($item['uri']) }}">
                       <input hidden name="searchKeyword" value="{{ $keyword }}">
                       <input hidden name="fullResult" value="{{ json_encode($result) }}">
                       <input hidden name="response" value="{{ json_encode($item) }}">

                       <div class="search-item-wrapper" data-item-id="{{ $uniqueId }}">
                           <button type="submit"
                               class="search-list-item d-flex align-items-start gap-3 p-3 text-decoration-none text-reset">
                               @if ($image)
                                   <div>
                                       <img src="{{ $image }}" alt="Thumbnail"
                                           class="rounded border object-fit-cover aspect-1"
                                           style="width: 40px; height: 40px;">
                                   </div>
                               @endif
                               <div class="d-flex gap-3 align-items-center justify-content-between w-100">
                                   <div class="d-flex flex-column flex-grow-1">
                                       <h5 class="fs-14 line-1 mb-1">{!! $highlightedTitle !!}</h5>
                                       <p class="text-muted fs-12 mb-0">{!! $highlightedUri !!}</p>
                                   </div>
                               </div>
                           </button>
                       </div>
                   </form>
               @endforeach
           @endforeach
       @else
           <div
               class="d-flex flex-column gap-3 align-items-center justify-content-center min-h-300 rounded text-body-light">
               <img width="40" height="40" class="svg"
                   src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/empty-state-icon/no-result-found.svg') }}"
                   alt="Image">
               <span class="fs-16">{{ translate('No_result_found') }}</span>
           </div>
       @endif
   </div>
