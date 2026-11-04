@extends('layouts.admin.app')

@section('title', translate('product_Bulk_Import'))

@section('content')
    <div class="content container-fluid">

        <div class="mb-4">
            <h1 class="mb-1 text-capitalize d-flex gap-2">
                <img src="{{dynamicAsset(path: 'public/assets/back-end/img/bulk-import.png')}}" alt="">
                {{translate('bulk_Import')}}
            </h1>
        </div>

        <div class="card mb-20">
            <div class="card-body">
                <div class="row g-4 mb-20">
                    <div class="col-md-6 col-lg-4">
                        <div class="border rounded-3 p-4 bg-white h-100">
                            <div class="p-xl-1">
                                <div class="d-flex align-items-center justify-content-between gap-2 mb-20">
                                    <div class="cont">
                                        <h3 class="fs-20 font-weight-normal fw-normal mb-1 lh-base d-block text-dark">{{ translate('Step_1') }} : </h3>
                                        <p class="fs-12 m-0 max-w-150px">{{ translate('Download_Excel_File') }}</p>
                                    </div>
                                    <img width="60" src="{{dynamicAsset(path: 'public/assets/back-end/img/xlsx-down.png')}}" alt="">
                                </div>
                                <div>
                                    <div class="fs-12 text-dark fw-semibold font-weight-semibold mb-3">
                                        {{ translate('Instruction') }}
                                    </div>
                                    <ul class="d-flex flex-column gap-10 ps-3 list-group">
                                        <li class="text-dark fs-12">
                                            {{ translate('Download the format file to get the required column structure.') }}
                                        </li>
                                        <li class="text-dark fs-12">
                                            {{ translate('Check the example file for accurate data input guidance.') }}
                                        </li>
                                        <li class="text-dark fs-12">
                                            {{ translate('Please upload the xlsx or excel file') }}
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-4">
                        <div class="border rounded-3 p-4 bg-white h-100">
                            <div class="p-xl-1">
                                <div class="d-flex align-items-center justify-content-between gap-2 mb-20">
                                    <div class="cont">
                                        <h3 class="fs-20 font-weight-normal fw-normal mb-1 lh-base d-block text-dark">{{ translate('Step_2') }} : </h3>
                                        <p class="fs-12 m-0 max-w-150px">{{ translate('Match_Spread_sheet_data_according_to_instruction') }}</p>
                                    </div>
                                    <img width="60" src="{{dynamicAsset(path: 'public/assets/back-end/img/proper-sheet.png')}}" alt="">
                                </div>
                                <div>
                                    <div class="fs-12 text-dark fw-semibold font-weight-semibold mb-3">
                                        {{ translate('Instruction') }}
                                    </div>
                                    <ul class="d-flex flex-column gap-10 ps-3 list-group">
                                        <li class="text-dark fs-12">
                                            {{ translate('Ensure all required columns in your Excel file are complete and match the downloaded format.') }}
                                        </li>
                                        <li class="text-dark fs-12">
                                            {{ translate('Check your data before uploading to avoid errors.') }}
                                        </li>
                                        <li class="text-dark fs-12">
                                            {{ translate('The system will automatically align columns during the final import') }}
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-4">
                        <div class="border rounded-3 p-4 bg-white h-100">
                            <div class="p-xl-1">
                                <div class="d-flex align-items-center justify-content-between gap-2 mb-20">
                                    <div class="cont">
                                        <h3 class="fs-20 font-weight-normal fw-normal mb-1 lh-base d-block text-dark">{{ translate('Step_3') }} : </h3>
                                        <p class="fs-12 m-0 max-w-150px">{{ translate('Validate_data_and_complete_import') }}</p>
                                    </div>
                                    <img width="60" src="{{dynamicAsset(path: 'public/assets/back-end/img/xlsx-up.png')}}" alt="">
                                </div>
                                <div>
                                    <div class="fs-12 text-dark fw-semibold font-weight-semibold mb-3">
                                        {{ translate('Instruction') }}
                                    </div>
                                    <ul class="d-flex flex-column gap-10 ps-3 list-group">
                                        <li class="text-dark fs-12">
                                            {{ translate('Upload your completed Excel or xlsx file using the upload tool.') }}
                                        </li>
                                        <li class="text-dark fs-12">
                                            {{ translate('Review the validation report for any errors.') }}
                                        </li>
                                        <li class="text-dark fs-12">
                                            {{ translate('After resolving errors, click "Import" to finalise adding products to your store') }}
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="text-center">
                    <p class="mb-15px fs-16 text-dark">{{ translate('Download_Spreadsheet_Template') }}</p>
                    <div class="d-flex align-items-center gap-3 justify-content-center flex-wrap">
                        <a href="{{dynamicAsset(path: 'public/assets/product_bulk_format.xlsx')}}" download=""
                           class="btn btn-secondary px-4 fs-14 fw-medium min-h-40">{{translate('With_Existing_Data')}}</a>
                        <a href="{{dynamicAsset(path: 'public/assets/product_bulk_format_without_data.xlsx')}}" download=""
                           class="btn btn-primary px-4 fs-14 fw-medium min-h-40">{{translate('Without_Any_Data')}}</a>
                    </div>
                </div>
            </div>
        </div>
        <form class="product-form form-advance-validation form-advance-inputs-validation form-advance-file-validation non-ajax-form-validate" action="{{route('admin.products.bulk-import')}}" method="POST"
                enctype="multipart/form-data" novalidate="novalidate">
            @csrf
            <div class="card rest-part">
                <div class="card-body">
                    <div class="text-center mb-20">
                        <h3 class="mb-0 fs16">{{translate("Import items file")}} ?</h3>
                    </div>
                    <div class="form-group mb-20">
                        <div class="row justify-content-center">
                            <div class="max-w-500 uplad-xls-file">
                                <div class="uploadDnD position-relative pt-3">
                                    <label for="inputFile" class="text-center d-block" style="cursor:pointer;">
                                        <img width="54"
                                             src="{{dynamicAsset(path: 'public/assets/back-end/img/xlsx-up.png')}}"
                                             alt=""
                                             class="view-img position- object-contain">
                                    </label>
                                    <div class="form-group inputDnD input_image input_image_edit"
                                         data-title="{{translate('drag_&_drop_file_or_browse_file')}}">
                                        <div class="text-center">
                                            <input type="file"
                                                   name="products_file"
                                                   accept=".xlsx, .xls"
                                                   class="form-control-file font-weight-bold action-upload-section-dot-area"
                                                   data-max-size="{{ getFileUploadMaxSize(type: 'file') }}"
                                                   data-required-msg="{{ translate('file_field_is_required') }}"
                                                   id="inputFile"
                                                   required>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex flex-wrap gap-10 align-items-center justify-content-end mt-4">
                        <button type="reset" class="btn btn-secondary px-4 action-onclick-reload-page">{{translate('reset')}}</button>
                        <button type="submit" class="btn btn-primary px-4">{{translate('submit')}}</button>
                    </div>
                </div>
            </div>
        </form>
    </div>
@endsection
