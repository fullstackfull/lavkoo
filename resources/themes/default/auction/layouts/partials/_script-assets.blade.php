<span id="route-currency-change" data-url="{{ route('currency.change') }}"></span>
<span id="route-pay-offline-method-list" data-url="{{ route('auction.pay-offline-method-list') }}"></span>
<span id="route-messages-store" data-url="{{ route('messages') }}"></span>
<span id="message-send-successfully" data-text="{{ translate('send_successfully') }}"></span>
<span id="route-auction-searched-products" data-url="{{ route('auction.searched-products') }}"></span>

<script src="{{ theme_asset(path: 'public/assets/front-end/auction/js/jquery-3.7.1.min.js') }}"></script>
<script src="{{ theme_asset(path: 'public/assets/front-end/auction/js/bootstrap.bundle.min.js') }}"></script>
<script src="{{ theme_asset(path: 'public/assets/front-end/auction/plugin/owl-carousel/owl.carousel.min.js') }}"></script>
<script src="{{ theme_asset(path: 'public/assets/front-end/auction/plugin/swiper/swiper-bundle.min.js') }}"></script>
<script src="{{ theme_asset(path: 'public/assets/front-end/auction/js/easyzoom.min.js') }}"></script>

<script src="{{ theme_asset(path: "public/assets/back-end/js/toastr.js" )}}"></script>
<script src="{{ dynamicAsset(path: 'public/assets/backend/libs/sweetalert2/sweetalert2.all.min.js') }}"></script>

<script src="{{ theme_asset(path: 'public/assets/front-end/auction/plugin/intl-tel-input/js/intlTelInput.min.js') }}"></script>
<script src="{{ theme_asset(path: 'public/assets/front-end/auction/plugin/intl-tel-input/js/utils.js') }}"></script>
<script src="{{ theme_asset(path: 'public/assets/front-end/auction/plugin/apexchart/apexcharts.js') }}"></script>
<script src="{{ theme_asset(path: 'public/assets/front-end/auction/js/easyzoom.min.js') }}"></script>
<script src="{{ theme_asset(path: 'public/assets/front-end/auction/js/single-image-upload.js') }}"></script>

<script src="{{ theme_asset(path: 'public/assets/front-end/auction/js/spartan-multi-image-picker.min.js') }}"></script>
<script src="{{ theme_asset(path: 'public/assets/front-end/auction/js/multiple-image-upload.js') }}"></script>

<script src="{{ theme_asset(path: 'public/assets/front-end/auction/plugin/quill-editor/quill-editor.js') }}"></script>
<script src="{{ theme_asset(path: 'public/assets/front-end/auction/plugin/quill-editor/quill-editor-init.js') }}"></script>
<script src="{{ theme_asset(path: 'public/assets/front-end/auction/plugin/daterangepicker/moment.min.js') }}"></script>
<script src="{{ theme_asset(path: 'public/assets/front-end/auction/plugin/daterangepicker/daterangepicker.min.js') }}"></script>
<script src="{{ theme_asset(path: 'public/assets/front-end/auction/js/select2.min.js') }}"></script>
<script src="{{ theme_asset(path: 'public/assets/front-end/auction/js/auction.js') }}"></script>

{!! Toastr::message() !!}

<script>
    "use strict";
    @if ($errors->any())
        @foreach($errors->all() as $error)
            toastr.error('{{$error}}', Error, {
                CloseButton: true,
                ProgressBar: true
            });
        @endforeach
    @endif
</script>

<script>
    let placeholderImageUrl = "{{ dynamicAsset(path: 'public/assets/new/back-end/img/svg/image-upload.svg') }}";
    const iconPath = "{{ dynamicAsset(path: 'public/assets/new/back-end/img/icons/file.svg') }}";
</script>

<script src="{{ dynamicAsset(path: 'public/assets/front-end/auction/js/auction-common.js') }}"></script>

@include('layouts.front-end.partials._firebase-script')

<script>
    "use strict";
    var $auctionSearchInput = $(".auction-search-input");

    $auctionSearchInput.keyup(function () {
        var $card = $(".auction-search-card");
        $card.show();
        var name = $auctionSearchInput.val();
        $auctionSearchInput.data("given-value", name);
        if (name.length > 0) {
            $.get({
                url: $("#route-auction-searched-products").data("url"),
                dataType: "json",
                data: { name: name },
                success: function (data) {
                    $(".auction-search-result-box").empty().html(data.result);
                    $(".auction-search-result-item").on("mouseover", function () {
                        $auctionSearchInput.val($(this).data("product-name"));
                    }).on("mouseleave", function () {
                        $auctionSearchInput.val($auctionSearchInput.data("given-value"));
                    }).on("click", function () {
                        var $form = $(this).closest("form");
                        $form.find(".auction-search-input").val($(this).data("product-name"));
                        $form.trigger("submit");
                    });
                },
            });
        } else {
            $(".auction-search-result-box").empty();
        }
    });

    $(document).on("click", function (e) {
        if (!$(e.target).closest(".auction-search-form").length) {
            $(".auction-search-card").hide();
            $(".auction-search-result-box").empty();
        }
    });

    // HTML5 search input "x" clear button fires the "search" event.
    // When it empties the input on a filtered listing, reload without the search filter.
    $auctionSearchInput.on("search", function () {
        if ($(this).val() === "" && new URLSearchParams(window.location.search).has("search")) {
            $(this).closest("form").trigger("submit");
        }
    });
</script>

<script>
    $('.js-daterangepicker').each(function () {
        const $input = $(this);
        const isPreviousDateAllowed = $input.hasClass('previous-date-true');
        const isPlaceholderMode = $input.hasClass('placeholder-mode-true');

        $input.daterangepicker({
            autoUpdateInput: !isPlaceholderMode,
            locale: {
                format: 'DD MMM YYYY'
            },
            minDate: !isPreviousDateAllowed ? moment() : false
        });

        if (isPlaceholderMode) {
            $input.attr('placeholder', 'DD MMM YYYY - DD MMM YYYY');
            $input.on('apply.daterangepicker', function (ev, picker) {
                $(this).val(picker.startDate.format('DD MMM YYYY') + ' - ' + picker.endDate.format('DD MMM YYYY'));
            });
            $input.on('cancel.daterangepicker', function (ev, picker) {
                $(this).val('');
            });
        }
    });
</script>

<script>
    $(function () {
        $(".price-range-wrapper").each(function () {

            let wrapper = $(this);
            let slider = wrapper.find(".price_range_slider");
            let minThumb = wrapper.find(".thumb_min");
            let maxThumb = wrapper.find(".thumb_max");
            let range = wrapper.find(".slider-range");
            let minInput = wrapper.find(".min_price");
            let maxInput = wrapper.find(".max_price");

            let sliderMin = slider.data('min-value') ?? 0;
            let sliderMax = slider.data('max-value') ?? 100000;

            let minValue = sliderMin;
            let maxValue = sliderMax;

            let isRtl = $('html').attr('dir') === 'rtl';

            function clamp(value, min, max) {
                return Math.min(Math.max(value, min), max);
            }

            function updateSlider(syncInputs = true) {
                let sliderWidth = slider.width();

                let minLeft = ((minValue - sliderMin) / (sliderMax - sliderMin)) * sliderWidth;
                let maxLeft = ((maxValue - sliderMin) / (sliderMax - sliderMin)) * sliderWidth;

                if (isRtl) {
                    minLeft = sliderWidth - minLeft;
                    maxLeft = sliderWidth - maxLeft;
                }

                minThumb.css(isRtl ? "insetInlineEnd" : "insetInlineStart", minLeft + "px");
                maxThumb.css(isRtl ? "insetInlineEnd" : "insetInlineStart", maxLeft + "px");

                range.css({
                    [isRtl ? 'insetInlineEnd' : 'insetInlineStart']: Math.min(minLeft, maxLeft) + "px",
                    width: Math.abs(maxLeft - minLeft) + "px",
                });

                if (syncInputs) {
                    minInput.val(minValue);
                    maxInput.val(maxValue);
                }
            }

            function handleDrag(thumb, isMinThumb) {

                thumb.on("mousedown touchstart", function (e) {

                    e.preventDefault();
                    let startX = e.pageX || e.originalEvent.touches[0].pageX;
                    let startValue = isMinThumb ? minValue : maxValue;
                    let sliderWidth = slider.width();

                    $(document).on("mousemove.slider touchmove.slider", function (e) {

                        let pageX = e.pageX || e.originalEvent.touches[0].pageX;
                        let deltaX = isRtl ? (startX - pageX) : (pageX - startX);
                        let valueChange = (deltaX / sliderWidth) * (sliderMax - sliderMin);

                        let newValue = clamp(startValue + valueChange, sliderMin, sliderMax);
                        newValue = Math.round(newValue * 100) / 100;

                        if (isMinThumb) {
                            minValue = Math.min(newValue, maxValue);
                        } else {
                            maxValue = Math.max(newValue, minValue);
                        }

                        updateSlider();
                    });

                    $(document).on("mouseup.slider touchend.slider", function () {
                        $(document).off(".slider");
                    });
                });
            }

            minInput.on("input", function () {
                let val = parseFloat($(this).val());
                if (!isNaN(val)) {
                    minValue = clamp(val, sliderMin, maxValue);
                    updateSlider(false);
                }
            });

            maxInput.on("input", function () {
                let val = parseFloat($(this).val());
                if (!isNaN(val)) {
                    maxValue = clamp(val, minValue, sliderMax);
                    updateSlider(false);
                }
            });

            handleDrag(minThumb, true);
            handleDrag(maxThumb, false);

            updateSlider();
        });

        $('.duration_range_slider').each(function () {

            let slider = $(this);
            let wrapper = slider.closest('.price-range-wrapper');

            let minThumb = wrapper.find(".duration_thumb_min");
            let maxThumb = wrapper.find(".duration_thumb_max");
            let range = wrapper.find(".duration_slider_range");
            let minInput = wrapper.find(".duration_min");
            let maxInput = wrapper.find(".duration_max");
            let typeSelect = wrapper.find(".duration_time_type");

            let sliderMin = slider.data('min-value') ?? 0;

            let maxMin = slider.data('max-min');
            let maxHour = slider.data('max-hour');
            let maxDay = slider.data('max-day');

            let sliderMax = maxMin;

            let minValue = sliderMin;
            let maxValue = sliderMax;

            let isRtl = $('html').attr('dir') === 'rtl';

            function clamp(value, min, max) {
                return Math.min(Math.max(value, min), max);
            }

            function updateSlider(syncInputs = true) {
                let sliderWidth = slider.width();
                if (sliderMax === sliderMin) return;

                let percentMin = (minValue - sliderMin) / (sliderMax - sliderMin);
                let percentMax = (maxValue - sliderMin) / (sliderMax - sliderMin);

                let minLeft = percentMin * sliderWidth;
                let maxLeft = percentMax * sliderWidth;

                if (isRtl) {
                    minLeft = sliderWidth - minLeft;
                    maxLeft = sliderWidth - maxLeft;
                }

                minThumb.css(isRtl ? "insetInlineEnd" : "insetInlineStart", minLeft + "px");
                maxThumb.css(isRtl ? "insetInlineEnd" : "insetInlineStart", maxLeft + "px");

                range.css({
                    [isRtl ? 'insetInlineEnd' : 'insetInlineStart']: Math.min(minLeft, maxLeft) + "px",
                    width: Math.abs(maxLeft - minLeft) + "px",
                });

                if (syncInputs) {
                    minInput.val(minValue);
                    maxInput.val(maxValue);
                }
            }

            function updateMaxByType() {
                let type = typeSelect.val();

                if (type === 'min') sliderMax = maxMin;
                else if (type === 'hour') sliderMax = maxHour;
                else if (type === 'day') sliderMax = maxDay;

                minValue = sliderMin;
                maxValue = sliderMax;

                slider.attr('data-max-value', sliderMax);

                updateSlider();
            }

            function handleDrag(thumb, isMinThumb) {

                thumb.on("mousedown touchstart", function (e) {
                    e.preventDefault();

                    let startX = e.pageX || e.originalEvent.touches[0].pageX;
                    let startValue = isMinThumb ? minValue : maxValue;

                    $(document).on("mousemove.slider touchmove.slider", function (e) {

                        let pageX = e.pageX || e.originalEvent.touches[0].pageX;
                        if (!pageX) return;

                        let sliderWidth = slider.width();
                        let currentMax = sliderMax;

                        let deltaX = isRtl ? (startX - pageX) : (pageX - startX);
                        let valueChange = (deltaX / sliderWidth) * (currentMax - sliderMin);

                        let newValue = clamp(Math.round((startValue + valueChange) * 100) / 100, sliderMin, currentMax);

                        if (isMinThumb) {
                            minValue = Math.min(newValue, maxValue);
                        } else {
                            maxValue = Math.max(newValue, minValue);
                        }

                        updateSlider();
                    });

                    $(document).on("mouseup.slider touchend.slider", function () {
                        $(document).off(".slider");
                    });
                });
            }

            minInput.on("input", function () {
                let val = parseFloat($(this).val());
                if (!isNaN(val)) {
                    minValue = clamp(val, sliderMin, maxValue);
                    updateSlider(false);
                }
            });

            maxInput.on("input", function () {
                let val = parseFloat($(this).val());
                if (!isNaN(val)) {
                    maxValue = clamp(val, minValue, sliderMax);
                    updateSlider(false);
                }
            });

            typeSelect.on("change", function () {
                updateMaxByType();
            });

            handleDrag(minThumb, true);
            handleDrag(maxThumb, false);

            updateMaxByType();

            $(window).on("resize", updateSlider);

            wrapper.closest("form").on("reset", function () {
                setTimeout(() => {
                    minValue = sliderMin;
                    updateMaxByType();
                }, 10);
            });

        });
    });
</script>

<script>
    $(document).ready(function () {

        $(".product-slider").each(function () {

            const $wrapper = $(this);
            const $big = $wrapper.find(".big-slider");

            // INIT OWL FIRST
            $big.owlCarousel({
                items: 1,
                loop: false,
                nav: true,
                dots: false
            });

            // FUNCTION TO INIT ZOOM
            function initZoom() {

                // Destroy all zoom instances
                $big.find(".easyzoom").each(function () {
                    const api = $(this).data("easyZoom");
                    if (api) {
                        api.teardown();
                        $(this).removeData("easyZoom");
                    }
                });

                // Get active slide (not cloned)
                const $activeSlide = $big.find(".owl-item.active").not(".cloned");

                const $zoomItem = $activeSlide.find(".easyzoom");

                if ($zoomItem.length) {
                    $zoomItem.easyZoom();
                }
            }

            // INIT FIRST TIME (delay needed for Owl)
            setTimeout(function () {
                initZoom();
            }, 200);

            // RE-INIT ON SLIDE CHANGE
            $big.on("changed.owl.carousel", function () {
                setTimeout(function () {
                    initZoom();
                }, 100);
            });

        });

    });
</script>

<script>
    var chartEl = document.querySelector("#chart");
    if (chartEl) {
        var options = {
            series: [{
                name: 'Sales',
                data: [4,3,10,9,29,19,22,9,12,7,19,5,13,9,17,2,7,5]
            }],
            chart: {
                height: 350,
                type: 'line',
                toolbar: {
                    show: false
                }
            },

            stroke: {
                width: 2,
                curve: 'smooth'
            },

            colors: ['#1455AC'],

            xaxis: {
                type: 'datetime',
                categories: [
                    '1/11/2000','2/11/2000','3/11/2000','4/11/2000','5/11/2000','6/11/2000',
                    '7/11/2000','8/11/2000','9/11/2000','10/11/2000','11/11/2000','12/11/2000',
                    '1/11/2001','2/11/2001','3/11/2001','4/11/2001','5/11/2001','6/11/2001'
                ],
                tickAmount: 10,
                labels: {
                    formatter: function(value, timestamp, opts) {
                        return opts.dateFormatter(new Date(timestamp), 'dd MMM')
                    }
                }
            },

            title: {
                text: 'Sales Trend',
                align: 'left',
                style: {
                    fontSize: "16px",
                    color: '#666'
                }
            }
        };
        var chart = new ApexCharts(chartEl, options);
        chart.render();
    }
</script>

<script>
    $(document).on("click", ".view_btn", function (e) {
        e.preventDefault();
        e.stopImmediatePropagation();

        let $card = $(this).closest(".upload-file");
        let $img = $card.find("img.upload-file-img");

        let actualSrc = $img.attr("src") || $img.attr("data-src") || $img.attr("data-default-src");

        if (actualSrc && actualSrc.length > 5) {
            let modalElement = document.getElementById('imageModal');

            if (modalElement) {
                $(modalElement).find(".imageModal_img").attr("src", actualSrc);
                $(modalElement).find(".download_btn").attr("href", actualSrc);

                try {
                    let myModal = new bootstrap.Modal(modalElement);
                    myModal.show();
                } catch (err) {
                    $(modalElement).modal('show');
                }
            }
        } else {
            console.error("Image source not found!");
        }
    });
</script>




