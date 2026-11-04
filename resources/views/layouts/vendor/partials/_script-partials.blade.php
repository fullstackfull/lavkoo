<script src="{{ dynamicAsset(path: 'public/assets/back-end/js/vendor.min.js') }}"></script>
<script src="{{ dynamicAsset(path: 'public/assets/back-end/js/theme.min.js') }}"></script>
<script src="{{ dynamicAsset(path: 'public/assets/back-end/vendor/hs-navbar-vertical-aside/hs-navbar-vertical-aside-mini-cache.js') }}"></script>
<script src="{{ dynamicAsset(path: 'public/assets/back-end/js/bootstrap.min.js') }}"></script>
<script src="{{ dynamicAsset(path: 'public/assets/backend/libs/jquery-validate/jquery.validate.min.js') }}"></script>
<script src="{{ dynamicAsset(path: 'public/assets/backend/libs/sweetalert2/sweetalert2.all.min.js') }}"></script>
<script src="{{ dynamicAsset(path: 'public/assets/new/back-end/libs/spartan-multi-image-picker/spartan-multi-image-picker-min.js') }}"></script>

<script src="{{ dynamicAsset(path: 'public/assets/backend/libs/intl-tel-input/js/intlTelInput.js') }}"></script>
<script src="{{ dynamicAsset(path: 'public/assets/backend/libs/intl-tel-input/js/utils.js') }}"></script>
<script src="{{ dynamicAsset(path: 'public/assets/backend/libs/intl-tel-input/js/intlTelInout-validation.js') }}"></script>

<script src="{{ dynamicAsset(path: 'public/assets/new/back-end/libs/swiper/swiper-bundle.min.js')}}"></script>
<script src="{{ dynamicAsset(path: 'public/assets/backend/vendor/js/promotion/offers-and-deals.js') }}"></script>
<script src="{{ dynamicAsset(path: 'public/assets/backend/libs/easyzoom/easyzoom.min.js') }}"></script>
<script src="{{ dynamicAsset(path: 'public/js/lightbox.min.js') }}"></script>

 <script src="{{ dynamicAsset(path: 'public/assets/new/back-end/js/single-image-upload.js') }}"></script>
<script src="{{ dynamicAsset(path: 'public/assets/new/back-end/js/multiple-image-upload.js') }}"></script>

<script src="{{ dynamicAsset(path: 'public/assets/back-end/js/custom.js') }}"></script>
<script src="{{ dynamicAsset(path: 'public/assets/back-end/js/app-script.js') }}"></script>
<script src="{{ dynamicAsset(path: 'public/assets/backend/vendor/js/custom-modal-plugin.js') }}"></script>
<script src="{{ dynamicAsset(path: 'public/assets/backend/common-script.js') }}"></script>
<script src="{{ dynamicAsset(path: 'public/assets/backend/vendor/custom.js') }}"></script>
<script src="{{ dynamicAsset(path: 'public/assets/new/back-end/js/select-2-init.js') }}"></script>


<script src="{{ dynamicAsset(path: 'public/assets/backend/vendor/js/AI/products/product-title-autofill.js') }}"></script>
<script src="{{ dynamicAsset(path: 'public/assets/backend/vendor/js/AI/products/product-description-autofill.js') }}"></script>
<script src="{{ dynamicAsset(path: 'public/assets/backend/vendor/js/AI/products/general-setup.js') }}"></script>
<script src="{{ dynamicAsset(path: 'public/assets/backend/vendor/js/AI/products/product-pricing.js') }}"></script>
    <script src="{{ dynamicAsset(path: 'public/assets/backend/vendor/js/AI/products/product-variation-setup.js') }}"></script>
<script src="{{ dynamicAsset(path: 'public/assets/backend/vendor/js/AI/products/seo-section-auto-fill.js') }}"></script>
<script src="{{ dynamicAsset(path: 'public/assets/backend/vendor/js/AI/products/ai-sidebar.js') }}"></script>
<script src="{{ dynamicAsset(path: 'public/assets/backend/vendor/js/AI/image-compressor/image-compressor.js') }}"></script>
<script src="{{ dynamicAsset(path: 'public/assets/backend/vendor/js/AI/image-compressor/compressor.min.js') }}"></script>

<script src="{{ dynamicAsset(path: 'public/assets/backend/file-validation/polyfills.js') }}"></script>
<script src="{{ dynamicAsset(path: 'public/assets/backend/file-validation/just-validate.min.js') }}"></script>
<script src="{{ dynamicAsset(path: 'public/assets/backend/file-validation/form-advance-validation.js') }}"></script>

<script src="{{ dynamicAsset(path: 'public/assets/backend/backend-utils.js') }}"></script>

{!! ToastMagic::scripts() !!}

@if ($errors->any())
    <script>
        'use strict';
        let errorsInitTime = 0;
        @foreach($errors->all() as $error)
            setTimeout(() => {
                toastMagic.error('{{ $error }}');
            }, errorsInitTime);
            errorsInitTime += 1000;
        @endforeach
    </script>
@endif

<script>
    let placeholderImageUrl = "{{ dynamicAsset(path: 'public/assets/new/back-end/img/svg/image-upload.svg') }}";
    const iconPath = "{{ dynamicAsset(path: 'public/assets/new/back-end/img/icons/file.svg') }}";
</script>

<script>
    // Circle Progress Bar
    let $progressPieChart = $(".progress-pie-chart"),
        percent = parseInt($progressPieChart.data("percent")),
        deg = (360 * percent) / 100;
    if (percent > 50) {
        $progressPieChart.addClass("gt-50");
    }
    $(".ppc-progress-fill").css("transform", "rotate(" + deg + "deg)");
    $(".ppc-percents span").html(percent + "%");
</script>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        window.updateBodyScrollState  = function () {
            const isAnyOffcanvasActive = document.querySelector('.offcanvas-sidebar.active') !== null;
            document.body.style.overflow = isAnyOffcanvasActive ? 'hidden' : '';
        }
        document.querySelectorAll('[data-toggle="offcanvas"]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const targetSelector = btn.getAttribute('data-target');
                const target = document.querySelector(targetSelector);
                if (target) {
                    target.classList.add('active');
                    updateBodyScrollState();
                }
            });
        });

        document.addEventListener('click', function (event) {
            const dismissTrigger = event.target.closest('[data-dismiss="offcanvas"]');
            if (dismissTrigger) {
                const sidebar = document.querySelector('.offcanvas-sidebar.active');
                if (sidebar) {
                    sidebar.classList.remove('active');
                    updateBodyScrollState();
                }
            }
        });

        const offcanvasSetupGuide = document.getElementById('offcanvasSetupGuide');
        if (offcanvasSetupGuide && offcanvasSetupGuide.getAttribute('data-status') === 'show') {
            setTimeout(() => {
                offcanvasSetupGuide.classList.add('active');
                updateBodyScrollState();
            }, 500)
        }
    });

    function openOffcanvasAfterModal(okBtnSelector, modalSelector, offcanvasSelector) {
        let shouldOpen = false;

        $(document).on('click', okBtnSelector, function () {
            shouldOpen = true;
        });

        $(document).on('hidden.bs.modal', modalSelector, function () {
            if (shouldOpen) {
                const offcanvas = document.querySelector(offcanvasSelector);
                if (offcanvas) {
                    setTimeout(() => {
                        offcanvas.classList.add('active');
                        updateBodyScrollState();

                    }, 500);
                }
                shouldOpen = false;
            }
        });
    }


</script>

<script>
    'use strict';
    $(document).ready(function () {
        // ---- single image upload starts
        $('.single_file_input').on('change', function (event) {
            var file = event.target.files[0];
            var $card = $(event.target).closest('.upload-file');
            var $textbox = $card.find('.upload-file-textbox');
            var $imgElement = $card.find('.upload-file-img');
            var $editBtn = $card.find('.edit-btn');
            var $removeBtn = $card.find('.remove-btn');

            if (file) {
                var reader = new FileReader();
                reader.onload = function (e) {
                    $textbox.hide();
                    $imgElement.attr('src', e.target.result).show();
                    $editBtn.removeClass('d-none');
                };
                reader.readAsDataURL(file);
            }
        });

        $('.edit-btn').on('click', function () {
            var $card = $(this).closest('.upload-file');
            var $fileInput = $card.find('.single_file_input');

            $fileInput.click();
        });

        // Check for a valid src on load to handle pre-existing images
        $('.upload-file').each(function () {
            var $card = $(this);
            var $textbox = $card.find('.upload-file-textbox');
            var $imgElement = $card.find('.upload-file-img');
            var $removeBtn = $card.find('.remove-btn');

            // If there's already a valid image source
            if ($imgElement.attr('src') && $imgElement.attr('src') !== window.location.href) {
                $textbox.hide();
                $imgElement.show();
                $removeBtn.removeClass('d-none');
            }
        });

        $('.remove-btn').click(function () {
            var $card = $(this).closest('.upload-file');
            var $textbox = $card.find('.upload-file-textbox');
            $card.find('.single_file_input').val('');
            $card.find('.upload-file-img').css('display', 'none');
            $textbox.show();
        });
    });
</script>

<script>
    'use strict'
    let getInitialDataForPanelTime = parseInt(
        $('#get-initial-data-for-panel-time').data('value'),
        10
    );
    setInterval(function () {
        getInitialDataForPanel();
    }, getInitialDataForPanelTime);
</script>

<script>

    $('.notification-data-view').on('click', function () {
        let id = $(this).data('id');
        $.ajaxSetup({
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            }
        });
        $.post({
            url: "{{route('vendor.notification.index')}}",
            data: {
                _token: '{{csrf_token()}}',
                id: id,
            },
            beforeSend: function () {
            },
            success: function (data) {
                $('.notification_data_new_badge' + id).fadeOut();
                $('#NotificationModalContent').empty().html(data.view);
                $('#NotificationModal').modal('show');
                let notificationDataCount = $('.notification_data_new_count');
                let notificationCount = parseInt(data.notification_count);
                notificationCount === 0 ? notificationDataCount.fadeOut() : notificationDataCount.html(notificationCount);
            },
            complete: function () {
            },
        });
    })
    if (/MSIE \d|Trident.*rv:/.test(navigator.userAgent)) document.write(
        '<script src="{{ dynamicAsset(path: 'public/assets/back-end') }}/vendor/babel-polyfill/polyfill.min.js"><\/script>');
</script>
@if(config('app.mode') == 'demo')
    <script>
        'use strict'

        function checkDemoResetTime() {
            let currentMinute = new Date().getMinutes();
            if (currentMinute > 55 && currentMinute <= 60) {
                $('#demo-reset-warning').addClass('active');
            } else {
                $('#demo-reset-warning').removeClass('active');
            }
        }

        checkDemoResetTime();
        setInterval(checkDemoResetTime, 60000);
    </script>
@endif

<script>
    $('.vendor-withdrawal-method').on('change', function () {
        const selectedOption = $(this).find('option:selected');
        const methodId = selectedOption.val();
        const methodType = selectedOption.data('type');

        $.ajaxSetup({
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            }
        });

        $.ajax({
            url: $(this).data('route'),
            type: 'POST',
            data: {
                method_id : methodId,
                method_type : methodType,
            },
            success: function (response) {
                $('#withdraw-request-method-filed').empty().html(response?.htmlView);
            },
            error: function () {

            }
        });
    });
</script>

<script src="{{ dynamicAsset(path: 'public/assets/kohl/js/kohl.js') }}"></script>

@include('layouts.vendor.partials._firebase-script')
