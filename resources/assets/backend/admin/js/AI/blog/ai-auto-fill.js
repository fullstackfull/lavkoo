$('[data-lang]').each(function () {
    const lang = $(this).data('lang');
    const selector = '#description-' + lang + '-editor';
    const $el = $(selector);

    if ($el.length) {
        new Quill(selector, {});
    } else {
        console.warn("Quill editor container not found for:", selector);
    }
});
document.addEventListener('DOMContentLoaded', function () {
    const modal = $('#aiAssistantModalBlog');
    const modalTitle = document.getElementById('modalTitleBlog');
    const mainContent = document.getElementById('mainAiContentBlog');
    const uploadContent = document.getElementById('uploadImageContentBlog');
    const titleContent = document.getElementById('giveTitleContentBlog');
    const imageUpload = document.getElementById('aiImageUploadBlog');
    const imagePreview = document.getElementById('imagePreviewBlog');
    const previewImg = document.getElementById('previewImgBlog');

    function showMainContent() {
        document.querySelectorAll('.ai-modal-content-blog').forEach(content => {
            content.style.display = 'none';
        });
        document.querySelector('.ai_backBtn').style.display = "none";
        mainContent.style.display = 'block';
        modalTitle.textContent = 'AI Assistant';
    }

    modal.on('show.bs.modal', function () {
        showMainContent();
    });

    document.querySelectorAll('.ai-action-btn-blog').forEach(button => {
        button.addEventListener('click', function () {
            const action = this.getAttribute('data-action');

            document.querySelectorAll('.ai-modal-content-blog').forEach(content => {
                content.style.display = 'none';
            });

            if (action === 'upload') {
                document.querySelector('.ai_backBtn').style.display = "block";
                modalTitle.textContent = 'Upload & Analyze Image';
                uploadContent.style.display = 'block';
            } else if (action === 'title') {
                modalTitle.textContent = 'Generate Blog Title';
                titleContent.style.display = 'block';
                document.querySelector('.ai_backBtn').style.display = "block";
            }
        });
    });
    if (imageUpload) {
        imageUpload.addEventListener('change', function (e) {
            $('#chooseImageBtnBlog').find('.text-box').addClass('d-none');
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
            $('#chooseImageBtnBlog').find('.text-box').removeClass('d-none');
        });
    }
    const backBtn = document.querySelector('.ai_backBtn');
    if (backBtn) {
        backBtn.addEventListener('click', function () {
            showMainContent();
        });
    }
});


$(document).off('click', '.blog_title_auto_fill').on('click', '.blog_title_auto_fill', function () {
    const $button = $(this);
    const lang = $button.data('lang');
    const route = $button.data('route');
    const $nameInput = $('#' + lang + '_title');
    const name = ($nameInput.val() || '').trim();
    console.log(name);
    const $editorContainer = $('#title-container-' + lang);

    if (name.length === 0) {
        toastMagic.error("Blog title is required");
        return;
    }

    let $existingTitle = $button.data('item')?.title ?? "";


    $editorContainer.addClass('outline-animating');
    $button.prop('disabled', true);
    $button.find('.btn-text').text('');
    const $aiText = $button.find('.ai-text-animation');
    $aiText.removeClass('d-none').addClass('ai-text-animation-visible');

    $.ajax({
        url: route,
        type: 'GET',
        dataType: 'json',
        data: {
            title: name,
            langCode: lang
        },
        success: function (response) {
            console.log(response);
            $nameInput.val(response.data.title);
        },
        error: function (xhr, status, error) {
            $editorContainer.removeClass('outline-animating');

            if (xhr.responseJSON && xhr.responseJSON.errors) {
                Object.values(xhr.responseJSON.errors).forEach(fieldErrors => {
                    fieldErrors.forEach(errorMessage => {
                        toastMagic.error(errorMessage);
                    });
                });
            } else if (xhr.responseJSON && xhr.responseJSON.message) {
                toastMagic.error(xhr.responseJSON.message);
            } else {
                toastMagic.error('An unexpected error occurred.');
            }

            $nameInput.val($existingTitle);
            $button.prop('disabled', false);
            $button.find('.btn-text').text('Re-generate');
            $aiText.addClass('d-none').removeClass('ai-text-animation-visible');
        },
        complete: function () {
            setTimeout(function () {
                $editorContainer.removeClass('outline-animating');
            }, 500);

            $button.prop('disabled', false);
            $button.find('.btn-text').text('Re-generate');
            $aiText.addClass('d-none').removeClass('ai-text-animation-visible');
        }
    });
});

$(document).on('click', '.blog_description_auto_fill', function () {
    const $button = $(this);
    const lang = $button.data('lang');
    const route = $button.data('route');
    const $nameInput = $('#' + lang + '_title');
    const name = ($nameInput.val() || '').trim();
    const $editorContainer = $('#editor-container-' + lang);
    const $editor = $('#description-' + lang + '-editor');
    const $textarea = $('#description-' + lang);
    const quillEditor = Quill.find($editor[0]);
    const $imageBtn = $('#analyzeBlogImageBtn');
    const $imageRemoveButton = $("#removeImageBtn");
    const $chooseImageBtn = $("#chooseImageBtn");
    if (name.length === 0) {
        toastMagic.error("Blog title is required to generate description");
        return;
    }


    let $existingDescription = $button.data('item')?.description || $textarea.val();

    $editorContainer.addClass('outline-animating');
    $button.prop('disabled', true);
    $button.find('.btn-text').text('');
    const $aiText = $button.find('.ai-text-animation');
    $aiText.removeClass('d-none').addClass('ai-text-animation-visible');

    $.ajax({
        url: route,
        type: 'GET',
        dataType: 'json',
        data: {
            title: name,
            langCode: lang
        },
        success: function (response) {
            console.log(response);
            if (quillEditor) {
                quillEditor.root.innerHTML = response.data;
                $textarea.val(response.data);
            }

            if ($button.data('next-action')?.toString() === 'seo_section') {
                if (!window.location.href.includes('draft-edit')) {
                    scrollToSection('seo');
                }
            }
        },
        error: function (xhr, status, error) {
            console.warn('Error:', error);

            if (quillEditor) {
                quillEditor.root.innerHTML = $existingDescription;
                $textarea.val($existingDescription);
            }
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
                toastMagic.error('An unexpected error occurred');
            }
        },
        complete: function () {
            if ($button.data('next-action')?.toString() === 'seo_section') {
                if (!window.location.href.includes('draft-edit')) {
                    setTimeout(function () {
                        const target = document.querySelector('.blog_seo_section_auto_fill');
                        if (target) {
                            target.click();
                        }
                    }, 300);
                } else {
                    console.log("Draft edit mode detected — skipping SEO auto-fill click.");
                }
            }
            if ($button && $button.attr('data-next-action')) {
                $button.removeAttr('data-next-action');
                $button.removeData('next-action');
                if ($button[0] && $button[0].dataset) {
                    delete $button[0].dataset.nextAction;
                }
            }
            setTimeout(function () {
                $editorContainer.removeClass('outline-animating');
            }, 500);
            $button.prop('disabled', false);
            $button.find('.btn-text').text('Re-generate');
            $aiText.addClass('d-none').removeClass('ai-text-animation-visible');

            if (window.location.href.includes('draft-edit')) {
                $imageRemoveButton.prop('disabled', false);
                $chooseImageBtn.removeClass('disabled');
                $button.prop('disabled', false);
                $button.find('.btn-text').text('Re-generate');
                $aiText.addClass('d-none').removeClass('ai-text-animation-visible');

                $imageBtn.prop('disabled', false);
                $imageBtn.find('.btn-text').text('Generate Blog');
                $imageBtn.find('.ai-btn-animation').addClass('d-none');
                $imageBtn.find('i').addClass('d-none');
            }
            const modalEl = document.getElementById('aiAssistantModalBlog');
            const modalInstance = bootstrap.Modal.getInstance(modalEl);
            const timeoutDuration = window.location.href.includes('draft-edit') ? 300 : 2500;
            if (modalInstance) {
                setTimeout(function () {
                    modalInstance.hide();
                }, timeoutDuration);
            }
        }
    });
});

$(document).on('click', '.blog_seo_section_auto_fill', function () {
    const $button = $(this);
    const lang = $button.data('lang');
    const route = $button.data('route');
    const name = $('#' + lang + '_title').val();
    const $editor = $('#description-' + lang + '-editor');
    const $imageRemoveButton = $("#removeImageBtn");
    const $chooseImageBtn = $("#chooseImageBtn");
    const quillEditor = Quill.find($editor[0]);
    const description = quillEditor ? quillEditor.root.innerHTML : '';
    const plainDescription = quillEditor ? quillEditor.getText().trim() : '';
    const $imageBtn = $('#analyzeBlogImageBtn');
    const $container = $('.seo_wrapper').find('.outline-wrapper');

    if (!name) {
        toastMagic.error("Blog title is required");
        return;
    }
    if (!plainDescription) {
        toastMagic.error("Blog description is required");
        return;
    }

    const existingData = {};
    $container.find('input, select, textarea').each(function () {
        const $field = $(this);
        const fieldName = $field.attr('name');
        if (!fieldName) return;

        if ($field.is(':checkbox')) {
            existingData[fieldName] = $field.prop('checked');
        } else {
            existingData[fieldName] = $field.val();
        }
    });
    $button.data('item', existingData);

    $container.addClass('outline-animating');
    $container.find('.bg-animate').addClass('active');
    $button.prop('disabled', true);
    $button.find('.btn-text').text('');
    const $aiText = $button.find('.ai-text-animation');
    $aiText.removeClass('d-none').addClass('ai-text-animation-visible');

    $.ajax({
        url: route,
        type: 'POST',
        dataType: 'json',
        data: { title: name, description: description,  _token: $('meta[name="csrf-token"]').attr('content') },
        success: function (response) {
            const data = response.data;

            $('#meta_title').val(data.meta_title);
            $('#meta_description').val(data.meta_description);

            $('input[name="meta_index"][value="' + data.meta_index + '"]').prop('checked', true);
            $('input[name="meta_no_follow"]').prop('checked', data.meta_no_follow == 1);
            $('input[name="meta_no_image_index"]').prop('checked', data.meta_no_image_index == 1);
            $('input[name="meta_no_archive"]').prop('checked', data.meta_no_archive == 1);
            $('input[name="meta_no_snippet"]').prop('checked', data.meta_no_snippet == 1);
            $('input[name="meta_max_snippet"]').prop('checked', data.meta_max_snippet == 1);
            $('input[name="meta_max_video_preview"]').prop('checked', data.meta_max_video_preview == 1);
            $('input[name="meta_max_image_preview"]').prop('checked', data.meta_max_image_preview == 1);

            $('input[name="meta_max_snippet_value"]').val(data.meta_max_snippet_value);
            $('input[name="meta_max_video_preview_value"]').val(data.meta_max_video_preview_value);
            $('select[name="meta_max_image_preview_value"]').val(data.meta_max_image_preview_value);
        },
        error: function (xhr) {
            const previousData = $button.data('item');
            Object.keys(previousData).forEach(key => {
                const $field = $container.find(`[name="${key}"]`);
                if (!$field.length) return;

                if ($field.is(':checkbox')) {
                    $field.prop('checked', previousData[key]);
                } else if ($field.is('select')) {
                    $field.val(previousData[key]).trigger('change');
                } else {
                    $field.val(previousData[key]);
                }
            });
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
                toastMagic.error('An unexpected error');
            }
        },
        complete: function () {
            setTimeout(function () {
                $container.removeClass('outline-animating');
                $container.find('.bg-animate').removeClass('active');
            }, 500);

            $imageRemoveButton.prop('disabled', false);
            $chooseImageBtn.removeClass('disabled');
            $button.prop('disabled', false);
            $button.find('.btn-text').text('Re-generate');
            $aiText.addClass('d-none').removeClass('ai-text-animation-visible');

            $imageBtn.prop('disabled', false);
            $imageBtn.find('.btn-text').text('Generate Blog');
            $imageBtn.find('.ai-btn-animation').addClass('d-none');
            $imageBtn.find('i').addClass('d-none');

            const modalEl = document.getElementById('aiAssistantModal');
            const modalInstance = bootstrap.Modal.getInstance(modalEl);
            if (modalInstance) {
                setTimeout(function () {
                    modalInstance.hide();
                }, 300);
            }
        }
    });
});

$('#generateBlogTitleBtn').on('click', function () {
    const $button = $(this);
    const keywords = $('#blogKeywords').val();
    const route = $button.data('route');

    if (!keywords) {
        toastMagic.error('Please enter some keywords.');
        return;
    }

    $button.prop('disabled', true);
    $button.find('.btn-text').text('Generating');
    $button.find('.ai-btn-animation').removeClass('d-none');
    $button.find('i').addClass('d-none');
    const $titlesList = $('#titlesList');
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
                $('input[name^="title["]').each(function () {
                    $(this).val(title);
                });
                $('input[name^="title["]').first()[0].scrollIntoView({
                    behavior: 'smooth',
                    block: 'center'
                });
            });

        },
        error: function (xhr, status, error) {
            console.warn(error);
            toastMagic.error('Failed to generate titles. Please try again.');
            $titlesList.empty();
        },
        complete: function () {
            $button.prop('disabled', false);
            $button.find('.btn-text').text('Generate Title');
            $button.find('.ai-btn-animation').addClass('d-none');
            $button.find('i').removeClass('d-none');

            $('.show_generating_text').addClass('d-none');
            $('.text-generate-icon').removeClass('d-none');

        }
    });
});

function scrollToSection(section) {
    const activeForm = document.querySelector('.form-system-language-form:not(.d-none)');
    if (!activeForm) return;

    const lang = activeForm.id.replace('-form', '');
    let target = null;

    switch (section) {
        case 'title':
            target = document.querySelector(`#title-container-${lang}`);
            break;
        case 'description':
            target = document.querySelector(`#editor-container-${lang}`);
            break;
        case 'seo':
            target = document.querySelector('.seo_wrapper');
            break;
        default:
            console.warn('Unknown section:', section);
            return;
    }

    if (!target) return;

    const offset = target.getBoundingClientRect().top + window.pageYOffset - 100;
    window.scrollTo({ top: offset, behavior: 'smooth' });
}


$(document).on('click', '#analyzeBlogImageBtn', function () {
    const $button = $(this);
    const $titleBtn = $('.blog_title_auto_fill');
    const $imageRemoveButton = $("#removeImageBtn")
    const $chooseImageBtn = $("#chooseImageBtnBlog")
    const route = $button.data('url') || $button.data('route');
    const imageInput = document.getElementById('aiImageUploadBlog');
    const originalimageInput = document.getElementById('aiImageUploadOriginalBlog');
    const lang = $button.data('lang');
    const $container = $('#title-container-' + lang);

    const $enLink = $('#en-link');
    const $enTitleForm = $('#en-form');
    const $enDesForm = $('#en-description-form');

    if ($enLink.length && $enTitleForm.length && $enDesForm.length && !$enLink.hasClass('active')) {
        $('.lang-link').removeClass('active');
        $('.form-system-language-form').addClass('d-none');
        $('.form-system-description-language-form').addClass('d-none');

        $enLink.addClass('active');
        $enTitleForm.removeClass('d-none');
        $enDesForm.removeClass('d-none');
    }


    if (!imageInput || !imageInput.files[0]) {
        toastMagic.error('Please select an image first');
        return;
    }
    const $blogTitleInput = $('input[name="title[en]"]');
    if ($blogTitleInput) {
        $blogTitleInput.trigger("focus");
        $blogTitleInput[0].scrollIntoView({
            behavior: 'smooth',
            block: 'center'
        });
    }
    $container.addClass('outline-animating');
    $container.find('.bg-animate').addClass('active');

    $button.prop('disabled', true);
    $button.find('.btn-text').text('Generating');
    $button.find('.ai-btn-animation').removeClass('d-none');
    $button.find('i').addClass('d-none');

    const formData = new FormData();
    formData.append('image', imageInput.files[0]);
    formData.append('description', $('#blog_description').val());

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
            $('#' + lang + '_title').val(response.data);

            const aiFile = originalimageInput.files[0];
            if (aiFile) {
                const dt1 = new DataTransfer();
                dt1.items.add(aiFile);
                document.getElementById('chooseImageBtnBlog').files = dt1.files;
                $("#chooseImageBtnBlog").trigger("change");
            }

            const $nameField = $('#' + lang + '_title');
            if ($nameField.length > 0) {
                scrollToSection('description');
            }

            const target = document.querySelector('.blog_description_auto_fill');
            if (target) {
                target.setAttribute('data-next-action', 'seo_section');
                target.click();
            }

            $titleBtn.find('.btn-text').text('Re-generate');
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
            $button.find('.btn-text').text('Generate Blog');
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
