{{--
    The rows of one address on an invoice: name, phone, where, street. Shared by the admin and the vendor invoice, which are the same table.

    $address is the order's copy of the address. A copy made with the structured address prints its region, city, district and National
    Address (see AddressSnapshotLines); an older copy prints the single "City / Zip" row it always did.
--}}
@use('App\Services\Address\AddressSnapshot')
@use('App\Services\Address\AddressSnapshotLines')
<tr>
    <td class="px-2 pt-1 pb-1">{{ translate('Name') }}</td>
    <td class="pt-1 pb-1">:</td>
    <td class="px-2 pt-1 pb-1">
        {{ $address->contact_person_name ?? null }}
    </td>
</tr>
<tr>
    <td class="px-2 pt-1 pb-1">{{ translate('Phone') }}</td>
    <td class="pt-1 pb-1">:</td>
    <td class="px-2 pt-1 pb-1"><span class="text-dark">{{ $address->phone ?? null }}</span></td>
</tr>
@if(AddressSnapshot::isUnified($address))
    @foreach(AddressSnapshotLines::location($address) as $line)
        <tr>
            <td class="px-2 pt-1 pb-1 text-nowrap">{{ translate($line['label']) }}</td>
            <td class="pt-1 pb-1">:</td>
            <td class="px-2 pt-1 pb-1"><span class="text-dark">{{ $line['value'] }}</span></td>
        </tr>
    @endforeach
@else
    <tr>
        <td class="px-2 pt-1 pb-1 text-nowrap">{{ translate('City_/_Zip') }}</td>
        <td class="pt-1 pb-1">:</td>
        <td class="px-2 pt-1 pb-1"><span class="text-dark">{{ $address->city ?? null }} {{ $address->zip ?? null }}</span></td>
    </tr>
@endif
<tr>
    <td class="px-2 pt-1 pb-1">{{ translate('Address') }}</td>
    <td class="pt-1 pb-1">:</td>
    <td class="px-2 pt-1 pb-1"><span class="text-dark">{{ $address->address ?? null }}</span></td>
</tr>
