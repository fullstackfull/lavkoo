$(document).ready(function () {
    function showErrorToast(message) {
        if (typeof toastMagic !== 'undefined' && typeof toastMagic.error === 'function') {
            toastMagic.error(message);
            return;
        }

        if (typeof toastr !== 'undefined' && typeof toastr.error === 'function') {
            toastr.error(message);
            return;
        }

        console.error(message);
    }

    function getDefaultAuctionLanguageCode() {
        const $defaultInput = $('.product-title-default-language').first();

        if (!$defaultInput.length) {
            return null;
        }

        const inputId = $defaultInput.attr('id') || '';
        return inputId.replace(/_name$/, '');
    }

    function getDefaultAuctionDescription() {
        const lang = getDefaultAuctionLanguageCode();

        if (!lang) {
            return '';
        }

        const editorElement = document.getElementById('description-' + lang + '-editor');
        const textareaValue = $('#description-' + lang).val() || '';

        if (!editorElement) {
            return textareaValue;
        }

        const quillEditor = $(editorElement).data('quill') || Quill.find(editorElement);
        return quillEditor ? quillEditor.root.innerHTML : textareaValue;
    }

    const DATERANGEPICKER_FORMAT = 'DD MMM YYYY, hh:mm A';

    function setAuctionDateField(fieldId, value) {
        const $input = $('#' + fieldId);

        if (!$input.length || !value) {
            return;
        }

        let formattedValue = value;

        if (typeof moment === 'function') {
            const parsed = moment(value, ['YYYY-MM-DD HH:mm:ss', 'YYYY-MM-DDTHH:mm:ss', 'YYYY-MM-DDTHH:mm', moment.ISO_8601], true);
            if (parsed.isValid()) {
                formattedValue = parsed.format(DATERANGEPICKER_FORMAT);
            }
        }

        $input.val(formattedValue).trigger('change');

        // Update picker internal state if it was already initialized on this field
        const picker = $input.data('daterangepicker');
        if (picker && typeof moment === 'function') {
            const parsed = moment(formattedValue, DATERANGEPICKER_FORMAT);
            if (parsed.isValid()) {
                picker.setStartDate(parsed);
                picker.setEndDate(parsed);
            }
        }
    }

    function toggleGeneratingState($button, isGenerating) {
        const $aiText = $button.find('.ai-text-animation');
        const $buttonText = $button.find('.btn-text');

        $button.prop('disabled', isGenerating);

        if (isGenerating) {
            $buttonText.text('');
            $aiText.removeClass('d-none').addClass('ai-text-animation-visible');
            return;
        }

        $buttonText.text('Re-generate');
        $aiText.addClass('d-none').removeClass('ai-text-animation-visible');
    }

    function showAuctionAiError(xhr, fallbackMessage) {
        const messages = [];

        // Tolerate every backend error shape: [{ error_code, message }],
        // { field: ['msg'] }, ['msg'], { field: 'msg' }, or a bare message.
        const collect = function (value) {
            if (value == null) {
                return;
            }
            if (Array.isArray(value)) {
                value.forEach(collect);
                return;
            }
            if (typeof value === 'object') {
                if (typeof value.message === 'string') {
                    messages.push(value.message);
                    return;
                }
                Object.values(value).forEach(collect);
                return;
            }
            messages.push(String(value));
        };

        if (xhr && xhr.responseJSON && xhr.responseJSON.errors) {
            collect(xhr.responseJSON.errors);
        }

        if (!messages.length && xhr && xhr.responseJSON && xhr.responseJSON.message) {
            messages.push(xhr.responseJSON.message);
        }

        if (!messages.length) {
            messages.push(fallbackMessage);
        }

        messages.forEach(showErrorToast);
    }

    function updateAiRemainingCount(response) {
        const remaining = response?.data?.remaining_count;

        if (typeof remaining === "number") {
            $("#ai-remaining-count").find("#count").text(remaining);
        }
    }

    $(document).on('click', '.auction-info-generate', function () {
        const $button = $(this);
        let stepSucceeded = false;
        const route = $button.data('route');
        const $container = $('.auction-info-section').first();
        const $defaultTitleInput = $('.product-title-default-language').first();
        const title = ($defaultTitleInput.val() || '').trim();
        const description = getDefaultAuctionDescription();

        if (!title.length) {
            showErrorToast('Auction product name is required to generate auction info');
            return;
        }

        $container.addClass('outline-animating');
        toggleGeneratingState($button, true);

        $.ajax({
            url: route,
            type: 'GET',
            dataType: 'json',
            data: {
                name: title,
                description: description
            },
            success: function (response) {
                updateAiRemainingCount(response);
                const data = response?.data?.data;

                if (!data || typeof data !== 'object') {
                    showErrorToast('Failed to generate auction info.');
                    return;
                }

                if (Object.prototype.hasOwnProperty.call(data, 'starting_price')) {
                    $('#starting_price').val(data.starting_price ?? '').trigger('input');
                }

                if (Object.prototype.hasOwnProperty.call(data, 'minimum_increment_amount')) {
                    $('#minimum_increment_amount').val(data.minimum_increment_amount ?? '').trigger('input');
                }

                if (Object.prototype.hasOwnProperty.call(data, 'maximum_decrement_amount')) {
                    $('#maximum_decrement_amount').val(data.maximum_decrement_amount ?? '').trigger('input');
                }

                if (Array.isArray(data.tax_ids) && $('#tax_ids').length) {
                    $('#tax_ids').val(data.tax_ids.map(String)).trigger('change');
                }

                if (Object.prototype.hasOwnProperty.call(data, 'start_time')) {
                    setAuctionDateField('auction_start_time', data.start_time || '');
                }

                if (Object.prototype.hasOwnProperty.call(data, 'end_time')) {
                    setAuctionDateField('auction_end_time', data.end_time || '');
                }

                // Tags input — customer themes mount bootstrap-tagsinput via
                // data-role="tagsinput", so val().trigger('change') will not
                // rebuild the chip widget. Drive the widget API directly when
                // it's initialised; fall back to a plain val() otherwise.
                const tagsArray = Array.isArray(data.tags)
                    ? data.tags
                    : (typeof data.tags_csv === 'string'
                        ? data.tags_csv.split(',')
                        : []);
                const cleanedTags = tagsArray
                    .map(function (t) { return String(t || '').trim(); })
                    .filter(function (t) { return t.length > 0; });

                const $tagsInput = $('#tags');
                if ($tagsInput.length && cleanedTags.length) {
                    // bootstrap-tagsinput inserts its widget BEFORE the original
                    // input, so detect the live instance via .data('tagsinput')
                    // rather than a DOM-position lookup. Drive the widget API when
                    // present; fall back to a plain val() otherwise.
                    if ($tagsInput.data('tagsinput')) {
                        $tagsInput.tagsinput('removeAll');
                        cleanedTags.forEach(function (t) {
                            $tagsInput.tagsinput('add', t);
                        });
                    } else {
                        $tagsInput.val(cleanedTags.join(', ')).trigger('change');
                    }
                }

                stepSucceeded = true;
            },
            error: function (xhr) {
                showAuctionAiError(xhr, 'An unexpected error occurred while generating auction info.');
            },
            complete: function () {
                setTimeout(function () {
                    $container.removeClass('outline-animating');
                }, 500);

                toggleGeneratingState($button, false);
                // Notify the AI sidebar cascade that this step finished.
                $(document).trigger('auction-ai-step-complete', [{ step: 'auction-info', success: stepSucceeded }]);
            }
        });
    });
});
