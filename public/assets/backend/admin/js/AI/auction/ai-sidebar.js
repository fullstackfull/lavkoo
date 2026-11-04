document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('auctionAiAssistantModal');
    const imageUpload = document.getElementById('auctionAiImageUpload');
    const imagePreview = document.getElementById('auctionImagePreview');
    const previewImg = document.getElementById('auctionPreviewImg');
    const chooseImageBtn = document.getElementById('auctionChooseImageBtn');
    const removeImageBtn = document.getElementById('auctionRemoveImageBtn');
    const progressText = document.getElementById('auctionAiProgressText');
    const modalTitle = document.getElementById('auctionAiModalTitle');

    // Switch between the modal's main menu / upload / generate-title sections.
    function showAuctionSection(section) {
        $('#auctionAiAssistantModal .ai-modal-content').hide();

        if (section === 'upload') {
            $('#auctionUploadImageContent').show();
            if (modalTitle) modalTitle.textContent = 'Upload & Analyze Image';
        } else if (section === 'title') {
            $('#auctionGiveTitleContent').show();
            if (modalTitle) modalTitle.textContent = 'Generate Product Title';
        } else {
            $('#auctionMainAiContent').show();
            if (modalTitle) modalTitle.textContent = 'AI Assistant';
        }
    }

    function resetAuctionTitleSection() {
        const $keywords = $('#auctionProductKeywords');
        // Clear tagsinput chips when the plugin is active, otherwise clear the plain value.
        if ($keywords.data('tagsinput')) {
            $keywords.tagsinput('removeAll');
        }
        $keywords.val('');
        $('#auctionTitlesList').empty();
        $('#auctionGeneratedTitles').hide();
        $('#auctionGeneratedTitles .show_generating_text').addClass('d-none');
        $('#auctionGeneratedTitles .titlesList_title').addClass('d-none');
    }

    function resetAuctionSidebar() {
        resetAuctionTitleSection();

        if (!imageUpload || !imagePreview || !chooseImageBtn) {
            return;
        }

        imageUpload.value = '';
        imagePreview.style.display = 'none';
        previewImg.src = '';
        $(chooseImageBtn).find('.text-box').removeClass('d-none');
        $(chooseImageBtn).removeClass('disabled');

        if (progressText) {
            progressText.textContent = '';
            progressText.classList.add('d-none');
        }

        $('.auction-ai-current').removeClass('auction-ai-current');
    }

    // Default to the main menu on load.
    showAuctionSection('main');

    if (modal) {
        const onModalShow = function () {
            resetAuctionSidebar();
            showAuctionSection('main');
        };
        // Bootstrap 5 (admin) dispatches a native event; Bootstrap 4 (vendor) fires it via jQuery.
        modal.addEventListener('show.bs.modal', onModalShow);
        $(modal).on('show.bs.modal', onModalShow);
    }

    // Main-menu navigation: open the chosen section.
    $('#auctionAiAssistantModal').on('click', '.ai-action-btn', function () {
        showAuctionSection($(this).data('action'));
    });

    if (imageUpload) {
        imageUpload.addEventListener('change', function (event) {
            $(chooseImageBtn).find('.text-box').addClass('d-none');
            const file = event.target.files[0];

            if (!file) {
                resetAuctionSidebar();
                return;
            }

            const reader = new FileReader();
            reader.onload = function (loadEvent) {
                previewImg.src = loadEvent.target.result;
                imagePreview.style.display = 'block';
            };
            reader.readAsDataURL(file);
        });
    }

    if (removeImageBtn) {
        removeImageBtn.addEventListener('click', function () {
            resetAuctionSidebar();
        });
    }
});

function runAuctionAiSequence(lang) {
    const steps = [
        {
            step: 'description',
            label: 'Generating description',
            target: '#auction-description-container-' + lang,
            trigger: function () {
                $('.auction-basic-setup-description-generate[data-lang="' + lang + '"]').trigger('click');
            }
        },
        {
            step: 'general-setup',
            label: 'Generating general setup',
            target: '.auction-general-setup-section',
            trigger: function () {
                $('.auction-general-setup-generate').first().trigger('click');
            }
        },
        {
            step: 'shipping-policy',
            label: 'Generating shipping policy',
            target: '.auction-shipping-policy-section',
            trigger: function () {
                $('.auction-shipping-policy-generate').first().trigger('click');
            }
        },
        {
            step: 'auction-info',
            label: 'Generating auction info',
            target: '.auction-info-section',
            trigger: function () {
                $('.auction-info-generate').first().trigger('click');
            }
        },
        {
            step: 'seo-section',
            label: 'Generating SEO section',
            target: '.auction-seo-section',
            trigger: function () {
                $('.auction-seo-generate').first().trigger('click');
            }
        }
    ];

    let currentIndex = 0;
    const progressText = document.getElementById('auctionAiProgressText');

    function clearActiveSection() {
        $('.auction-ai-current').removeClass('auction-ai-current');
    }

    function scrollToSection(targetSelector) {
        const $target = $(targetSelector).first();

        if (!$target.length) {
            return;
        }

        const $highlightTarget = $target.hasClass('card') ? $target : $target.closest('.card');
        clearActiveSection();

        if ($highlightTarget.length) {
            $highlightTarget.addClass('auction-ai-current');
        }

        $('html, body').stop(true).animate({
            scrollTop: Math.max($target.offset().top - 120, 0)
        }, 500);
    }

    function closeAuctionAiModal() {
        const modalEl = document.getElementById('auctionAiAssistantModal');

        if (!modalEl) {
            return;
        }

        if (window.bootstrap && window.bootstrap.Modal && typeof window.bootstrap.Modal.getOrCreateInstance === 'function') {
            window.bootstrap.Modal.getOrCreateInstance(modalEl).hide();
            return;
        }

        if (window.bootstrap && window.bootstrap.Modal && typeof window.bootstrap.Modal.getInstance === 'function') {
            const modalInstance = window.bootstrap.Modal.getInstance(modalEl);

            if (modalInstance) {
                modalInstance.hide();
                return;
            }
        }

        if (typeof $ === 'function' && typeof $(modalEl).modal === 'function') {
            $(modalEl).modal('hide');
        }
    }

    function finishSequence() {
        clearActiveSection();

        if (progressText) {
            progressText.textContent = 'AI generation completed';
            progressText.classList.remove('d-none');
        }

        setTimeout(function () {
            closeAuctionAiModal();
        }, 600);
    }

    function runNextStep() {
        if (currentIndex >= steps.length) {
            finishSequence();
            return;
        }

        const currentStep = steps[currentIndex];

        if (progressText) {
            progressText.textContent = currentStep.label;
            progressText.classList.remove('d-none');
        }

        scrollToSection(currentStep.target);

        $(document).one('auction-ai-step-complete', function (_event, payload) {
            if (!payload || payload.step !== currentStep.step) {
                runNextStep();
                return;
            }

            currentIndex += 1;
            setTimeout(function () {
                runNextStep();
            }, payload.success ? 500 : 300);
        });

        currentStep.trigger();
    }

    runNextStep();
}

$(document).on('click', '#auctionAnalyzeImageBtn', function () {
    const $button = $(this);
    const route = $button.data('url') || $button.data('route');
    const lang = $button.data('lang');
    const imageInput = document.getElementById('auctionAiImageUpload');
    const $chooseImageBtn = $('#auctionChooseImageBtn');
    const $container = $('#auction-title-container-' + lang);
    const $titleField = $('#' + lang + '_name');
    const $titleCard = $container.closest('.card');
    const progressText = document.getElementById('auctionAiProgressText');

    if (!imageInput || !imageInput.files[0]) {
        toastMagic.error('Please select an image first');
        return;
    }

    $chooseImageBtn.addClass('disabled');
    $('.auction-ai-current').removeClass('auction-ai-current');
    $titleCard.addClass('auction-ai-current');

    if (progressText) {
        progressText.textContent = 'Generating product title';
        progressText.classList.remove('d-none');
    }

    if ($titleField.length) {
        $('html, body').animate({
            scrollTop: $titleField.offset().top - 100
        }, 500);
    }

    $container.addClass('outline-animating');
    $button.prop('disabled', true);
    $button.find('.btn-text').text('Generating');
    $button.find('.ai-btn-animation').removeClass('d-none');

    const formData = new FormData();
    formData.append('image', imageInput.files[0]);

    $.ajax({
        url: route,
        type: 'POST',
        dataType: 'json',
        processData: false,
        contentType: false,
        headers: {
            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
        },
        data: formData,
        success: function (response) {
            if (response?.data?.remaining_count !== undefined) {
                $('#ai-remaining-count #count').text(response.data.remaining_count);
            }

            const generatedTitle = response?.data?.data;

            if (typeof generatedTitle !== 'string' || !generatedTitle.length) {
                toastMagic.error('Failed to analyze image and generate auction title.');
                return;
            }

            $titleField.val(generatedTitle).trigger('input').trigger('focus');
            $('#auction-title-' + lang + '-action-btn .btn-text').text('Re-generate');
            runAuctionAiSequence(lang);
        },
        error: function (xhr) {
            if (xhr.responseJSON && xhr.responseJSON.errors) {
                Object.values(xhr.responseJSON.errors).forEach(function (fieldErrors) {
                    fieldErrors.forEach(function (message) {
                        toastMagic.error(message);
                    });
                });
            } else if (xhr.responseJSON && xhr.responseJSON.message) {
                toastMagic.error(xhr.responseJSON.message);
            } else {
                toastMagic.error('An unexpected error occurred during image analysis.');
            }
        },
        complete: function () {
            setTimeout(function () {
                $container.removeClass('outline-animating');
                $chooseImageBtn.removeClass('disabled');
            }, 300);

            $button.prop('disabled', false);
            $button.find('.btn-text').text('Generate Product');
            $button.find('.ai-btn-animation').addClass('d-none');
        }
    });
});

// Generate Product Name: keywords/prompt -> multiple title suggestions.
$(document).on('click', '#auctionGenerateTitleBtn', function () {
    const $button = $(this);
    const route = $button.data('route');
    const lang = $button.data('lang');
    const keywords = $('#auctionProductKeywords').val();
    const $titlesList = $('#auctionTitlesList');
    const $generated = $('#auctionGeneratedTitles');
    const $loader = $button.find('.ai-loader-animation');
    const $icon = $button.find('.text-generate-icon');

    if (!keywords || !keywords.trim().length) {
        toastMagic.error('Please enter a keyword or prompt first');
        return;
    }

    $button.prop('disabled', true);
    $loader.removeClass('d-none');
    $icon.addClass('d-none');
    $generated.show();
    $generated.find('.show_generating_text').removeClass('d-none');
    $generated.find('.titlesList_title').addClass('d-none');
    $titlesList.empty();

    $.ajax({
        url: route,
        method: 'POST',
        data: {
            keywords: keywords,
            _token: $('meta[name="csrf-token"]').attr('content')
        },
        success: function (response) {
            const titles = response?.data?.data?.titles || [];
            $generated.find('.show_generating_text').addClass('d-none');
            $titlesList.empty();

            if (!titles.length) {
                $titlesList.html('<div class="text-center py-3">No titles generated.</div>');
                return;
            }

            $generated.find('.titlesList_title').removeClass('d-none');

            titles.forEach(function (title) {
                const $useBtn = $('<button type="button" class="btn btn-sm btn-outline-primary px-4 auction-use-title-btn">')
                    .text('Use')
                    .attr('data-lang', lang)
                    .data('title', title);
                const $row = $('<div class="list-group-item list-group-item-action title-option p-0">')
                    .append(
                        $('<div class="d-flex justify-content-between align-items-center gap-2">')
                            .append($('<span class="overflow-wrap-anywhere">').text(title))
                            .append($useBtn)
                    );
                $titlesList.append($row);
            });

            if (response?.data?.remaining_count !== undefined) {
                $('#ai-remaining-count #count').text(response.data.remaining_count);
            }
        },
        error: function (xhr) {
            $generated.find('.show_generating_text').addClass('d-none');

            if (xhr.responseJSON && xhr.responseJSON.errors) {
                Object.values(xhr.responseJSON.errors).forEach(function (fieldErrors) {
                    fieldErrors.forEach(function (message) {
                        toastMagic.error(message);
                    });
                });
            } else if (xhr.responseJSON && xhr.responseJSON.message) {
                toastMagic.error(xhr.responseJSON.message);
            } else {
                toastMagic.error('Failed to generate product titles.');
            }
        },
        complete: function () {
            $button.prop('disabled', false);
            $loader.addClass('d-none');
            $icon.removeClass('d-none');
        }
    });
});

// Use a suggested title: fill the product title field (no cascade).
$(document).on('click', '.auction-use-title-btn', function (e) {
    e.preventDefault();

    const title = $(this).data('title');
    const lang = $(this).data('lang');
    const $titleField = $('#' + lang + '_name');

    if (!$titleField.length) {
        return;
    }

    $titleField.val(title).trigger('input').trigger('focus');
    $('#auction-title-' + lang + '-action-btn .btn-text').text('Re-generate');
    $titleField[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
});
