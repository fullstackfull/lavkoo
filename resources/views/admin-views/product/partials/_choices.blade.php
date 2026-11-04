@foreach($choice_options as $key=>$choice)
    <div class="col-md-12 col-lg-6">
        <div class="form-group mb-0">
            <input type="hidden" name="choice_no[]" value="{{ $choice_no[$key] ?? '' }}">
            <label class="form-label">{{ $choice['title'] }}</label>
            <input type="text" name="choice[]" value="{{ $choice['title'] }}" hidden>
            <div class="">
                <input type="text" class="form-control call-update-sku alpha-numeric-input"
                       name="choice_options_{{$choice_no[$key]??''}}[]"
                       data-role="tagsinput"
                       value="@foreach($choice['options'] as $c){{$c.','}}@endforeach">
            </div>
        </div>
    </div>
@endforeach
