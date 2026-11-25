{{-- Test-only stand-in for the real screen, which needs the admin shell and the installation database: the markets this employee may
     change, the destinations they may be opened to, and the pairs a person recorded that they are allowed to see. --}}
@foreach ($sources as $source)[source:{{ $source }}]@endforeach
@foreach ($destinations as $destination)[destination:{{ $destination }}]@endforeach
@foreach ($recorded as $pair)[pair:{{ $pair->source_country }}:{{ $pair->destination_country }}:{{ $pair->is_open ? 'open' : 'closed' }}]@endforeach
@if (!$ready)[not-migrated]@endif
@if ($killSwitch)[stopped]@endif
