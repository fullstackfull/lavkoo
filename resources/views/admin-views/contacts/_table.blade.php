<div class="k-table-wrap">
    <table class="k-table">
        <thead>
        <tr>
            <th>{{translate('SL')}}</th>
            <th>{{translate('customer_Name')}}</th>
            <th>{{translate('contact_Info')}}</th>
            <th>{{translate('subject')}}</th>
            <th>{{translate('time_&_Date')}}</th>
            <th>{{translate('reply_status')}}</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        @foreach($contacts as $key => $contact)
            <tr>
                <td><span class="k-num">{{$contacts->firstItem()+$key}}</span></td>
                <td>{{$contact['name']}}</td>
                <td>
                    <div class="k-num">{{$contact['mobile_number']}}</div>
                    <div class="k-text-subtle">{{$contact['email']}}</div>
                </td>
                <td class="text-wrap">{{$contact['subject']}}</td>
                <td>
                    <span class="k-num">{{date('d M,Y h:i A',strtotime($contact['created_at']))}}</span>
                </td>
                <td>
                    @if(empty($contact['reply']))
                        <x-k.badge tone="info">{{translate('No')}}</x-k.badge>
                    @else
                        <x-k.badge tone="success">{{translate('Yes')}}</x-k.badge>
                    @endif
                </td>
                <td>
                    <div class="k-table__actions">
                        <a title="{{translate('view')}}"
                           class="k-btn k-btn--ghost k-btn--sm k-btn--icon"
                           href="{{route('admin.contact.view',$contact->id)}}">
                            <x-k.icon name="eye" :size="15" />
                        </a>
                        <span class="k-btn k-btn--ghost k-btn--sm k-btn--icon delete delete-data-without-form" role="button"
                              data-id="{{$contact['id']}}"
                              data-action="{{route('admin.contact.delete')}}"
                              title="{{ translate('delete')}}">
                            <x-k.icon name="trash" :size="15" />
                        </span>
                    </div>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@if(count($contacts)==0)
    <x-k.empty icon="marketing" :title="translate('no_data_to_show')" />
@endif
<div class="k-pager">
    <span class="k-pager__info">
        @if ($contacts->total() > 0)
            {{ translate('showing') }}
            <span class="k-num">{{ $contacts->firstItem() }}–{{ $contacts->lastItem() }}</span>
            {{ translate('of') }} <span class="k-num">{{ $contacts->total() }}</span>
        @endif
    </span>
    <div>{!! $contacts->appends(request()->except('page'))->links() !!}</div>
</div>
