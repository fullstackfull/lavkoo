"use strict";

// ------ TOAST HANDLER
function showErrorToast(message) {
    if (typeof toastMagic !== "undefined") {
        if (typeof toastr !== "undefined" && toastr.clear) toastr.clear();
        document.querySelectorAll('.toast-item').forEach(toast => toast.remove());
        toastMagic.error(message);
    } else if (typeof toastr !== "undefined") {
        toastr.clear();
        toastr.error(message);
    } else {
        alert(message);
    }
}

// ------ CUSTOM VALIDATION RULES
$.validator.addMethod("fileSize", function (value, element, maxSize) {
    if (!element.files || element.files.length === 0) return true;

    for (let i = 0; i < element.files.length; i++) {
        if (element.files[i].size > maxSize) {
            const $card = $(element).closest('.upload-file');
            element.value = '';
            if ($card.length) {
                setTimeout(() => {
                    if (typeof resetFileUpload === 'function') resetFileUpload($card[0]);
                    else {
                        $card.find('.upload-file-img').hide().attr('src', '');
                        $card.find('.upload-file-textbox').show();
                        $card.find('.overlay').removeClass('show');
                        $card.find('.remove_btn').css('opacity', 0);
                        $card[0].classList.remove('input-disabled');
                    }
                }, 10);
            }
            return false;
        }
    }
    return true;
}, function () {
    return $("#text-validate-translate").data("file-size-larger");
});

$.validator.addMethod("fileType", function (value, element) {
    if (!element.files || element.files.length === 0) return true;

    const acceptAttr = element.getAttribute("accept");
    if (!acceptAttr) return true;

    const acceptedTypes = acceptAttr
        .split(/[,|]/)
        .map(t => t.trim().toLowerCase())
        .filter(Boolean);

    for (let file of element.files) {
        const fileName = file.name.toLowerCase();
        const fileType = file.type.toLowerCase();

        const valid = acceptedTypes.some(acc => {
            if (acc.startsWith('.')) return fileName.endsWith(acc);
            if (acc.endsWith('/*')) return fileType.startsWith(acc.replace('/*', ''));
            return fileType === acc;
        });

        if (!valid) {
            const $card = $(element).closest('.upload-file');
            element.value = '';
            if ($card.length) {
                setTimeout(() => {
                    if (typeof resetFileUpload === 'function') resetFileUpload($card[0]);
                    else {
                        $card.find('.upload-file-img').hide().attr('src', '');
                        $card.find('.upload-file-textbox').show();
                        $card.find('.overlay').removeClass('show');
                        $card.find('.remove_btn').css('opacity', 0);
                        $card[0].classList.remove('input-disabled');
                    }
                }, 10);
            }
            showErrorToast($("#text-validate-translate").data("file-type-not-allowed") || "Invalid file type selected");
            return false;
        }
    }
    return true;
}, function () {
    return $("#text-validate-translate").data("file-type-not-allowed");
});

$.validator.addMethod("strongPassword", function (value, element) {
    return this.optional(element) || /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{8,}$/.test(value);
});

$.validator.addMethod("maxTextLength", function (value, element, max) {
    if (!value) return true;
    return value.length <= max;
}, function () {
    return $("#text-validate-translate").data("max-limit-crossed");
});

// ------ SCROLL TO FIRST INVALID FIELD
async function scrollToErrorField(field) {
    if (!field) return;

    const $field = $(field);
    const $target = field.type === "file"
        ? ($field.closest(".error-wrapper").length ? $field.closest(".error-wrapper") : $field)
        : $field;

    const revealPromises = [];

    $target.parents().each(function () {
        const $parent = $(this);

        // Bootstrap Tabs
        if ($parent.hasClass("tab-pane") && !$parent.hasClass("active")) {
            const id = $parent.attr("id");
            const $trigger = $(`[data-bs-toggle="tab"][href="#${id}"],[data-bs-target="#${id}"]`);
            if ($trigger.length) {
                $trigger.trigger("click");
                revealPromises.push(new Promise(res => {
                    $parent.one("shown.bs.tab", res);
                    setTimeout(res, 300);
                }));
            }
        }

        // Bootstrap Collapse
        if ($parent.hasClass("collapse") && !$parent.hasClass("show")) {
            const id = $parent.attr("id");
            const $trigger = $(`[data-bs-toggle="collapse"][data-bs-target="#${id}"]`);
            if ($trigger.length) {
                $trigger.trigger("click");
                revealPromises.push(new Promise(res => {
                    $parent.one("shown.bs.collapse", res);
                    setTimeout(res, 300);
                }));
            }
        }

        // Language forms
        if ($parent.hasClass("lang_form") && $parent.hasClass("d-none")) {
            $(".lang_form").addClass("d-none");
            $(".lang_link").removeClass("active");
            $("#" + $parent.attr("id")).removeClass("d-none");
            $("#" + $parent.attr("id").replace("-form", "-link")).addClass("active");
            revealPromises.push(Promise.resolve());
        }

        if ($parent.is(":hidden")) $parent.show();
    });

    await Promise.all(revealPromises);

    setTimeout(() => {
        if ($target.is(":visible")) {
            $target[0].scrollIntoView({ behavior: "smooth", block: "center" });
            try { field.focus({ preventScroll: true }); } catch (e) { }
        }
    }, 200);
}

// ------ DELEGATED AJAX HANDLER
function handleAjaxForm(selector, ajaxCallback) {
    $(document).on("ajax-submit", selector, function(e, form) {
        e.preventDefault();
        ajaxCallback(form);
    });
}

// ------ IFORM VALIDATION INIT
function initValidationForForm($form) {
    if ($form.data("validator")) return;

    let fileRules = {};
    $form.find("input[type='file']").each(function () {
        const $f = $(this);
        const name = $f.attr("name");
        if (!name) return;
        let maxMB = Number($f.data("max-size")) || 2;
        fileRules[name] = { fileSize: maxMB * 1024 * 1024, fileType: true };
    });

    $form.validate({
        ignore: [], // Validate hidden fields too
        rules: fileRules,
        onfocusout: false,
        onkeyup: false,
        onclick: false,
        showErrors: function (map, list) {
            this.errorList = list;
            this.errorMap = map;
            if (list.length) {
                let err = list[0];
                let msg = err.message;
                const $el = $(err.element);
                if (err.method === "required") {
                    msg = $el.data('required-msg') || $("#text-validate-translate").data("required-field") || msg;
                }
                showErrorToast(msg);
                scrollToErrorField(err.element);
            }
        },
        invalidHandler: function (e, validator) {
            if (validator.errorList.length) scrollToErrorField(validator.errorList[0].element);
        },
        submitHandler: function (form) {
            const $form = $(form);
            if ($form.data("ajax") === true) $form.trigger("ajax-submit", [form]);
            else form.submit();
        }
    });

    // Helper to add file rules for dynamically inserted inputs
    $form.data("addFileRules", function ($f) {
        const name = $f.attr("name");
        if (!name) return;
        const maxMB = Number($f.data("max-size")) || 2;
        try {
            $f.rules("add", { fileSize: maxMB * 1024 * 1024, fileType: true });
        } catch (e) { /* no-op */ }
    });

    // Add rules dynamically for each field
    $form.find("input, textarea, select").each(function () {
        const $field = $(this);
        const type = $field.attr("type") || ($field.is("textarea") ? "textarea" : "select");

        if ($field.prop("required")) $field.rules("add", { required: true });
        if (type === "text" || $field.is("textarea")) {
            const maxLen = parseInt($field.attr("maxlength"), 10);
            if (!isNaN(maxLen)) $field.rules("add", { maxTextLength: maxLen });
        }
        if (type === "email") $field.rules("add", { email: true });
        if (type === "password") $field.rules("add", { strongPassword: true });
        if ($field.attr("name") === "confirmPassword") {
            const $pw = $form.find('input[type="password"]').not('[name="confirmPassword"]').first();
            if ($pw.length) $field.rules("add", { equalTo: $pw });
        }
    });
}


$(function () {
    // ------ INIT EXISTING FORMS
     $(".custom-validation").each(function () { initValidationForForm($(this)); });

    // ------ OBSERVE DYNAMICALLY ADDED FORMS
    const observer = new MutationObserver(mutations => {
        $(mutations).each((_, mutation) => {
            const $added = $(mutation.addedNodes);
            // Initialize any newly added forms
            $added.find(".custom-validation").addBack(".custom-validation").each(function () {
                initValidationForForm($(this));
            });
            // Add rules for newly added file inputs inside existing validated forms
            $added.find("input[type='file']").each(function () {
                const $input = $(this);
                const $form = $input.closest("form.custom-validation");
                if ($form.length && $form.data("validator")) {
                    const addFileRules = $form.data("addFileRules");
                    if (typeof addFileRules === 'function') addFileRules($input);
                }
            });
        });
    });
    observer.observe(document.body, { childList: true, subtree: true });

    // ------ GLOBAL DELEGATED VALIDATION EVENTS
    $(document)
        .on("input", ".custom-validation input[type='text'], .custom-validation textarea", function () {
            const $form = $(this).closest("form.custom-validation");
            if ($form.length) $form.validate().element(this);
        })
        .on("change", ".custom-validation select, .custom-validation input[type='file']", function () {
            const $form = $(this).closest("form.custom-validation");
            if ($form.length) $form.validate().element(this);
        });

    $(document).on("shown.bs.tab shown.bs.collapse", function () {
        $(".custom-validation").each(function () {
            $(this).validate().form();
        });
    });

});


