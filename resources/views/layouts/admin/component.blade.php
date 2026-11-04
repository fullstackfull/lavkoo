@extends('layouts.admin.app')
@section('title', translate('general_Settings'))

@push('css_or_js')
    <link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/backend/admin/css/component-snippets.css') }}">
    <link href="{{ dynamicAsset(path: 'public/assets/new/back-end/libs/highlight/highlight-default.min.css') }}" rel="stylesheet" />
@endpush

@section('content')

    <div class="container-fluid">
        <div class="d-flex flex-column gap-4">
            @include('layouts.admin.components.dropdown')
            @include('layouts.admin.components.inputs')
            @include('layouts.admin.components.buttons')
            @include('layouts.admin.components.icon-buttons')
            @include('layouts.admin.components.badge')
            @include('layouts.admin.components.tab-menu')
            @include('layouts.admin.components.toaster')
            @include('layouts.admin.components.tooltip')
            @include('layouts.admin.components.images')
            @include('layouts.admin.components.file-upload')
            @include('layouts.admin.components.table')
            @include('layouts.admin.components.modal')
            @include('layouts.admin.components.offcanvas')
            @include('layouts.admin.components.notes')
            @include('layouts.admin.partials._alert-message')
        </div>
    </div>

@endsection

@push('script')
    <script src="{{ dynamicAsset(path: 'public/assets/new/back-end/libs/highlight/highlight.min.js') }}"></script>
    <script>
        document.addEventListener("DOMContentLoaded", () => {
            hljs.initHighlightingOnLoad();
            document.querySelectorAll(".component-snippets-code-container code").forEach((block) => {
                block.textContent = block.innerHTML.trim();
            });

            // Add copy functionality
            document.querySelectorAll('.copy-button').forEach(button => {
                button.addEventListener('click', () => {
                    const codeBlock = button.closest('.component-snippets-container').querySelector('.tab-pane.active code');
                    navigator.clipboard.writeText(codeBlock.textContent).then(() => {
                        // Change icon temporarily to show success
                        const icon = button.querySelector('i');
                        icon.classList.remove('fi-rr-copy');
                        icon.classList.add('fi-rr-check');

                        setTimeout(() => {
                            icon.classList.remove('fi-rr-check');
                            icon.classList.add('fi-rr-copy');
                        }, 1000);
                    });
                });
            });


            // Offcanvas Swiper Slider Activate
            var swiper = new Swiper(".mySwiper", {
                navigation: {
                    nextEl: ".swiper-button-next1",
                    prevEl: ".swiper-button-prev1",
                },
                pagination: {
                    el: ".swiper-pagination1",
                },
            });


            var swiper2 = new Swiper(".mySwiper2", {
                navigation: {
                    nextEl: ".swiper-button-next2",
                    prevEl: ".swiper-button-prev2",
                },
                pagination: {
                    el: ".swiper-pagination2",
                    type: "fraction",
                },
            });

            // New offcanvas slider combined above two
            document.querySelectorAll('.collapse').forEach((collapse) => {
                collapse.addEventListener('shown.bs.collapse', function () {
                    if (!collapse.classList.contains('swiper-initialized')) {
                        const swiperContainer = collapse.querySelector('.myOffcanvasSwiper');
                        const fractionEl = collapse.querySelector('.swiper-pagination-fraction');
                        const bulletsEl = collapse.querySelector('.swiper-pagination-bullets');
                        const nextBtns = collapse.querySelectorAll('.swiper-button-next-offcanvas, .bullet-next');
                        const prevBtns = collapse.querySelectorAll('.swiper-button-prev-offcanvas, .bullet-prev');

                        if (!swiperContainer) return;

                        const swiper = new Swiper(swiperContainer, {
                            centeredSlides: true,
                            navigation: {
                                nextEl: Array.from(nextBtns),
                                prevEl: Array.from(prevBtns),
                            },
                            pagination: {
                                el: bulletsEl,
                                type: "bullets",
                                clickable: true,
                            },
                            on: {
                                init: function () {
                                    updateFraction(this);
                                },
                                slideChange: function () {
                                    updateFraction(this);
                                }
                            }
                        });

                        function updateFraction(swiperInstance) {
                            if (fractionEl) {
                                const current = swiperInstance.realIndex + 1;
                                const total = swiperInstance.slides.length;
                                fractionEl.textContent = `${current} / ${total}`;
                            } else {
                                console.warn("Fraction element not found");
                            }
                        }

                        collapse.classList.add('swiper-initialized');
                    }
                });
            });


        });
    </script>
@endpush

