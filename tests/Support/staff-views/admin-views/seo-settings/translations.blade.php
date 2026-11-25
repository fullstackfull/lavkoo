{{-- Test-only stand-in: the twenty most recent SEO overrides the screen lists, by the row each hangs off. --}}
@foreach ($recent as $row)[seo:{{ $row->id }} {{ class_basename($row->seoable_type) }}:{{ $row->seoable_id }}]@endforeach
