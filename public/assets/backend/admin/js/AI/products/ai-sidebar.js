document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('aiAssistantModal');
    const modalTitle = document.getElementById('modalTitle');
    const mainContent = document.getElementById('mainAiContent');
    const uploadContent = document.getElementById('uploadImageContent');
    const titleContent = document.getElementById('giveTitleContent');
    const imageUpload = document.getElementById('aiImageUpload');
    const imagePreview = document.getElementById('imagePreview');
    const previewImg = document.getElementById('previewImg');

    function showMainContent() {
        document.querySelectorAll('.ai-modal-content').forEach(content => {
            content.style.display = 'none';
        });
        mainContent.style.display = 'block';
        modalTitle.textContent = 'AI Assistant';
    }

    if (modal) {
        modal.addEventListener('show.bs.modal', function () {
            showMainContent();
        });
    }

    document.querySelectorAll('.ai-action-btn').forEach(button => {
        button.addEventListener('click', function () {
            const action = this.getAttribute('data-action');

            document.querySelectorAll('.ai-modal-content').forEach(content => {
                content.style.display = 'none';
            });

            if (action === 'upload') {
                modalTitle.textContent = 'Upload & Analyze Image';
                uploadContent.style.display = 'block';
            } else if (action === 'title') {
                modalTitle.textContent = 'Generate Product Title';
                titleContent.style.display = 'block';
            }
        });
    });

    if (imageUpload){
        imageUpload.addEventListener('change', function (e) {
            $('#chooseImageBtn').find('.text-box').addClass('d-none');
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function (e) {
                    previewImg.src = e.target.result;
                    imagePreview.style.display = 'block';
                };
                reader.readAsDataURL(file);
            }
        });
    }

    if (document.getElementById('removeImageBtn')) {
        document.getElementById('removeImageBtn').addEventListener('click', function () {
            imageUpload.value = '';
            imagePreview.style.display = 'none';
            $('#chooseImageBtn').find('.text-box').removeClass('d-none');
        });
    }

    $('#generateTitleBtn').on('click', function () {
        const $button = $(this);
        const keywords = $('#productKeywords').val();
        const route = $button.data('route');

        if (!keywords) {
            toastMagic.error('Please enter some keywords.');
            return;
        }

        const $spinner = $button.find('.ai-loader-animation');
        const $titlesList = $('#titlesList');

        $spinner.removeClass('d-none');
        $button.prop('disabled', true);
        $('.giveTitleContent_text').addClass('d-none');
        $('#generatedTitles').show();
        $('.show_generating_text').removeClass('d-none');
        $('.text-generate-icon').addClass('d-none');

        $.ajax({
            url: route,
            method: 'POST',
            data: {
                keywords: keywords,
                _token: $('meta[name="csrf-token"]').attr('content')
            },
            success: function (response) {
                $titlesList.empty();

                if (!response.data.titles || response.data.titles.length === 0) {
                    $titlesList.html('<div class="text-center py-3">No titles generated.</div>');
                    return;
                }

                response.data.titles.forEach(function (title) {
                    const $item = $(`
                    <div class="list-group-item list-group-item-action title-option p-0">
                        <div class="d-flex justify-content-between align-items-center gap-2">
                            <span class="overflow-wrap-anywhere">${title}</span>
                            <button class="btn btn-sm btn-outline-primary px-4 use-title-btn" data-title="${title}">Use</button>
                        </div>
                    </div>
                `);
                    $titlesList.append($item);
                });

                $titlesList.before($('.titlesList_title').removeClass('d-none'));
                $('#generatedTitles').show();

                $titleActionButton = $('#title-' + 'en' + '-action-btn');
                $('.use-title-btn').off('click').on('click', function (e) {
                    e.preventDefault();

                    const title = $(this).data('title');
                    const $productNameInput = $('input[name="name[]"]');

                    if ($productNameInput.length) {
                        $productNameInput.val(title);
                        $productNameInput.trigger("focus");
                        $productNameInput[0].scrollIntoView({
                            behavior: 'smooth',
                            block: 'center'
                        });
                        $titleActionButton.find('.btn-text').text('Re-generate');
                    }
                });


            },
            error: function (xhr, status, error) {
                console.warn(error);
                toastMagic.error('Failed to generate titles. Please try again.');
                $titlesList.empty();
            },
            complete: function () {

                $spinner.addClass('d-none');
                $button.prop('disabled', false);
                $('.show_generating_text').addClass('d-none');
                $('.text-generate-icon').removeClass('d-none');
            }
        });
    });

});

$(document).on('click', '#analyzeImageBtn', function () {
    const $button = $(this);
    const $imageRemoveButton = $("#removeImageBtn")
    const $chooseImageBtn = $("#chooseImageBtn")
    const route = $button.data('url') || $button.data('route');
    const imageInput = document.getElementById('aiImageUpload');
    const originalimageInput = document.getElementById('aiImageUploadOriginal');
    const lang = $button.data('lang');
    const $container = $('#title-container-' + lang);
    // $imageRemoveButton.prop('disabled', true);
    // $chooseImageBtn.prop('disabled', true);

    if (!imageInput || !imageInput.files[0]) {
        toastMagic.error('Please select an image first');
        return;
    } else {
        $chooseImageBtn.addClass('disabled');
    }

    const $titleField = $('#' + lang + '_name');
    if ($titleField.length > 0) {
        $('html, body').animate({
            scrollTop: $titleField.offset().top - 100
        }, 800);
    }

    $container.addClass('outline-animating');
    $container.find('.bg-animate').addClass('active');

    $button.prop('disabled', true);
    $button.find('.btn-text').text('Generating');
    $button.find('.ai-btn-animation').removeClass('d-none');
    $button.find('i').addClass('d-none');

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
            console.log(response);
            $('#' + lang + '_name').val(response.data);

            const aiFile = originalimageInput.files[0];
            if (aiFile) {
                const dt1 = new DataTransfer();
                dt1.items.add(aiFile);
                document.getElementById('chooseImageBtn').files = dt1.files;
                $("#chooseImageBtn").trigger("change");
            }

            const $nameField = $('#' + lang + '_name');
            if ($nameField.length > 0) {
                $('html, body').animate({
                    scrollTop: $nameField.offset().top - 100
                }, 800);
            }
            setTimeout(function () {
                const $card = $('.card:has(.auto_fill_description)');
                $('html, body').animate({
                    scrollTop: $card.offset().top - 100
                }, 800);
                $('.auto_fill_description[data-lang="' + lang + '"]').trigger('click');

                waitForDescriptionAndContinue(lang, $button, $imageRemoveButton, $chooseImageBtn);


                setTimeout(function () {
                    $container.removeClass('outline-animating');
                    $container.find('.bg-animate').removeClass('active');
                }, 500);

                $button.prop('disabled', false);
                $button.find('.btn-text').text('Re-generate');

            }, 3000);

        },
        error: function (xhr, status, error) {
            $container.removeClass('outline-animating');
            $container.find('.bg-animate').removeClass('active');
            if (xhr.responseJSON && xhr.responseJSON.errors) {
                const errors = xhr.responseJSON.errors;
                Object.keys(errors).forEach(key => {
                    errors[key].forEach(message => {
                        toastMagic.error(message);
                    });
                });
            } else if (xhr.responseJSON && xhr.responseJSON.message) {
                toastMagic.error(xhr.responseJSON.message);
            } else {
                toastMagic.error('An unexpected error occurred during image analysis.');
            }

            $imageRemoveButton.prop('disabled', false);
            $chooseImageBtn.removeClass('disabled');
            $button.prop('disabled', false);
            $button.find('.btn-text').text('Generate Product Description');
            $button.find('.ai-btn-animation').addClass('d-none');
            $button.find('i').removeClass('d-none');
        },
        complete: function () {
            setTimeout(function () {
                $container.removeClass('outline-animating');
                $chooseImageBtn.removeClass('disabled');
            }, 500);
        }
    });
});

function waitForDescriptionAndContinue(lang, $button, $imageRemoveButton, $chooseImageBtn) {
    const descriptionField = $('#description-' + lang);
    let checkCount = 0;
    const maxChecks = 15;

    const checkDescription = setInterval(function () {
        checkCount++;
        let hasContent = false;
        if (descriptionField.length > 0) {
            const content = descriptionField.val() || descriptionField.text();
            hasContent = content && content.trim().length > 0;
        }

        if (hasContent || checkCount >= maxChecks) {
            clearInterval(checkDescription);

            const steps = [
                { selector: '.general_setup_auto_fill', delay: 4000 },
                { selector: '.price_others_auto_fill', delay: 2000 },
                { selector: '.variation_setup_auto_fill', delay: 3500 },
                { selector: '.seo_section_auto_fill', delay: 5000 }
            ];
            let currentStep = 0;

            function executeNextStep() {
                if (currentStep >= steps.length) {
                    $imageRemoveButton.prop('disabled', false);
                    $chooseImageBtn.prop('disabled', false);
                    $button.prop('disabled', false);
                    $button.find('.btn-text').text('Generate Product Description');
                    $button.find('.ai-btn-animation').addClass('d-none');
                    $button.find('i').removeClass('d-none');
                    const modalEl = document.getElementById('aiAssistantModal');
                    const modalInstance = bootstrap.Modal.getInstance(modalEl);
                    if (modalInstance) {
                        modalInstance.hide();
                    }
                    return;
                }

                const step = steps[currentStep];
                const $card = $('.card:has(' + step.selector + ')');
                if ($card.length > 0) {
                    $('html, body').animate({
                        scrollTop: $card.offset().top - 100
                    }, 800);
                }


                const $trigger = $(step.selector + '[data-lang="' + lang + '"]');
                $trigger.trigger('click');

                const checkInterval = setInterval(() => {
                    const isDone = $trigger.data('completed');
                    if (isDone) {
                        clearInterval(checkInterval);
                        currentStep++;
                        executeNextStep();
                    }
                }, 500);

                setTimeout(() => {
                    clearInterval(checkInterval);
                    currentStep++;
                    executeNextStep();
                }, step.delay + 2000);
            }

            executeNextStep();
        }
    }, 600);
}

document.querySelectorAll('.outline-wrapper').forEach(wrapper => {
    const child = wrapper.firstElementChild;
    if (child) {
        const radius = getComputedStyle(child).borderRadius;
        wrapper.style.borderRadius = radius;
    }
});
