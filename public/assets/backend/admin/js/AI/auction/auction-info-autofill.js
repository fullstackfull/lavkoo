$(document).ready(function () {
    function getAuctionDateInput(fieldName) {
        return $('#' + fieldName).length ? $('#' + fieldName) : $('[name="' + fieldName + '"]').first();
    }

    const DATERANGEPICKER_FORMAT = 'DD MMM YYYY, hh:mm A';

    function normalizeAuctionDateValue(value, $input) {
        if (!$input.length || !value) {
            return value || '';
        }

        if ($input.attr('type') === 'datetime-local') {
            if (typeof moment === 'function') {
                const parsedValue = moment(value);

                if (parsedValue.isValid()) {
                    return parsedValue.format('YYYY-MM-DDTHH:mm');
                }
            }

            return String(value).replace(' ', 'T').slice(0, 16);
        }

        // Convert AI MySQL format to daterangepicker display format
        if ($input.hasClass('js-select2-date') && typeof moment === 'function') {
            const parsed = moment(value, ['YYYY-MM-DD HH:mm:ss', 'YYYY-MM-DDTHH:mm', moment.ISO_8601], true);
            if (parsed.isValid()) {
                return parsed.format(DATERANGEPICKER_FORMAT);
            }
        }

        return value;
    }

    function setAuctionDateField(fieldName, value) {
        const $input = getAuctionDateInput(fieldName);

        if (!$input.length) {
            return;
        }

        const formattedValue = normalizeAuctionDateValue(value, $input);
        $input.val(formattedValue).trigger('input').trigger('change');

        // Update picker internal state if it was already initialized on this field
        const picker = $input.data('daterangepicker');
        if (picker && formattedValue && typeof moment === 'function') {
            const parsed = moment(formattedValue, DATERANGEPICKER_FORMAT);
            if (parsed.isValid()) {
                picker.setStartDate(parsed);
                picker.setEndDate(parsed);
            }
        }
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
        if (xhr.responseJSON && xhr.responseJSON.errors) {
            Object.values(xhr.responseJSON.errors).forEach(function (fieldErrors) {
                fieldErrors.forEach(function (message) {
                    toastMagic.error(message);
                });
            });
            return;
        }

        if (xhr.responseJSON && xhr.responseJSON.message) {
            toastMagic.error(xhr.responseJSON.message);
            return;
        }

        toastMagic.error(fallbackMessage);
    }

    function updateAiRemainingCount(response) {
        const remaining = response?.data?.remaining_count;

        if (typeof remaining === "number") {
            $("#ai-remaining-count").find("#count").text(remaining);
        }
    }

    $(document).on('click', '.auction-info-generate', function () {
        const $button = $(this);
        const route = $button.data('route');
        const $container = $('.auction-info-section').first();
        const $defaultTitleInput = $('.product-title-default-language').first();
        const title = ($defaultTitleInput.val() || '').trim();
        const description = getDefaultAuctionDescription();
        const existingData = $button.data('item') || {};
        let generationSucceeded = false;

        if (!title.length) {
            toastMagic.error('Auction product name is required to generate auction info');
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
                    toastMagic.error('Failed to generate auction info.');
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

                // Tags input — admin/vendor pages mount bootstrap-tagsinput
                // via data-role="tagsinput", so val().trigger('change') will
                // not rebuild the chip widget. Drive the widget API directly
                // when it's initialised; fall back to a plain val() otherwise.
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

                generationSucceeded = true;
            },
            error: function (xhr) {
                if (Object.prototype.hasOwnProperty.call(existingData, 'starting_price')) {
                    $('#starting_price').val(existingData.starting_price ?? '').trigger('input');
                }

                if (Object.prototype.hasOwnProperty.call(existingData, 'entry_fee')) {
                    $('#minimum_order_qty').val(existingData.entry_fee ?? '').trigger('input');
                }

                if (Object.prototype.hasOwnProperty.call(existingData, 'minimum_order_qty')) {
                    $('#minimum_order_qty').val(existingData.minimum_order_qty ?? '').trigger('input');
                }

                if (Object.prototype.hasOwnProperty.call(existingData, 'maximum_decrement_amount')) {
                    $('#maximum_decrement_amount').val(existingData.maximum_decrement_amount ?? '').trigger('input');
                }

                if (Array.isArray(existingData.tax_ids) && $('#tax_ids').length) {
                    $('#tax_ids').val(existingData.tax_ids).trigger('change');
                }

                if (Object.prototype.hasOwnProperty.call(existingData, 'auction_start_time')) {
                    setAuctionDateField('auction_start_time', existingData.auction_start_time || '');
                }

                if (Object.prototype.hasOwnProperty.call(existingData, 'auction_end_time')) {
                    setAuctionDateField('auction_end_time', existingData.auction_end_time || '');
                }

                showAuctionAiError(xhr, 'An unexpected error occurred while generating auction info.');
            },
            complete: function () {
                setTimeout(function () {
                    $container.removeClass('outline-animating');
                }, 500);

                toggleGeneratingState($button, false);
                $(document).trigger('auction-ai-step-complete', [{
                    step: 'auction-info',
                    success: generationSucceeded
                }]);
            }
        });
    });
});
