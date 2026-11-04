'use strict';
$(".lang-link").click(function (e) {
    e.preventDefault();
    $('.lang-link').removeClass('active');
    $(".lang-form").addClass('d-none');
    $(this).addClass('active');
    let formId = this.id;
    let lang = formId.split("-")[0];
    $(".language-wise-details").removeClass('show').removeClass('active');
    $("#" + lang + "-form").addClass('show active');
});

$(".action-for-lang-tab").click(function (e) {
    e.preventDefault();
    $('.action-for-lang-tab').removeClass('active');
    $(this).addClass('active');
    $($(this).data('target-group')).removeClass('show').removeClass('active');
    $($(this).data('target-tab')).addClass('show').addClass('active');
});

$(".action-for-lang-tab-offcanvas").click(function (e) {
    e.preventDefault();
    $('.action-for-lang-tab-offcanvas').removeClass('active');
    $(this).addClass('active');
    $($(this).data('target-group')).removeClass('show').removeClass('active');
    $($(this).data('target-tab')).addClass('show').addClass('active');

    const productName = $(this).data('name');
    const productNameField = $(this).data('name-field');
    $('.product-name-heading-title').addClass('d-none');
    if (productName) {
        $(productNameField).text(productName).removeClass('d-none');
    }
});

$('textarea[name=denied_note]').on('keyup', function() {
    let text = $(this).val().trim();
    let wordCount = countWords($(this).val());
    if (wordCount >= 99) {
        wordCount = 100;
        const words = text.split(/\s+/);
        $(this).val(words.slice(0, wordCount).join(' '));
    }
    $('#denied-note-word-count').text(wordCount + '/100');
});

$(document).ready(function () {
    let activeLanguage = null;
    $(document).on('click', '.action-for-lang-tab', function() {
        activeLanguage = this.id.replace('-link', '');
    });
    $('#offcanvasProductDetails').on('show.bs.offcanvas', function() {
        if (activeLanguage) {
            $('#offcanvas-pills-tab .nav-link').removeClass('active');
            $('.language-wise-offcanvas-details').removeClass('show active');

            const $offcanvasTab = $(`#offcanvas-pills-tab #${activeLanguage}-link`);
            const $offcanvasPane = $(`#${activeLanguage}-offcanvas-form`);

            if ($offcanvasTab.length && $offcanvasPane.length) {
                $offcanvasTab.addClass('active');
                $offcanvasPane.addClass('show active');
                const productName = $offcanvasTab.data('name');
                const productNameField = $offcanvasTab.data('name-field');
                $('.product-name-heading-title').addClass('d-none');
                if (productName) {
                    $(productNameField).text(productName).removeClass('d-none');
                }
            }
        }
    });
});
