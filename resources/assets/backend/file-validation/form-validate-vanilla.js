(function (global, $) {
    "use strict";

    // ✅ Step 1: Add global rule definitions BEFORE your FormMagicValidation code
    if (typeof $.validator !== "undefined") {
        // --- File Size Rule (in KB)
        $.validator.addMethod("fileSize", function (value, element, maxSize) {
            if (!element.files || element.files.length === 0) return true;
            const readableMaxSize = Math.round(maxSize / (1024 * 1024)) + " MB";
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
                    showErrorToast(($("#text-validate-translate").data("file-size-larger") || "File size is too large") + ` (Max size is: ${readableMaxSize})`
                    );
                    return false;
                }
            }
            return true;
        }, function (param) {
            const readableMaxSize = Math.round(param / (1024 * 1024)) + " MB";
            const baseMessage = $("#text-validate-translate").data("file-size-larger") || "File size is too large";
            return `${baseMessage} (Max: ${readableMaxSize})`;
        });

        // --- File Type Rule
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
    }

    const FormMagicValidation = function (form) {

        if (!form) return null;

        const $form = $(form);

        // --- Ensure browser native validation is disabled
        $form.attr('novalidate', true);

        let magic = $form.data("formMagicValidator");
        if (magic) return magic; // reuse existing instance

        console.log("✅ Done 12");

        // --- Collect messages and DOM order
        const messages = {};
        const domOrderMap = {};

        $form.find("input,textarea,select").each(function (index) {
            const name = $(this).attr("name");
            if (!name) return;
            const msg = $(this).attr("data-required-msg");
            if (msg) {
                messages[name] = { required: msg };
                domOrderMap[name] = index + 1;
            }
        });

        // --- Initialize jQuery Validate
        const validator = $form.validate({
            ignore: ":hidden:not(.select2-hidden-accessible):not(textarea[required])",
            messages: messages,
            onfocusout: false,
            onkeyup: false,
            onclick: false,
            errorPlacement: function (error, element) {
                const $wrap = element.closest(".error-wrapper");
                if (!error.text().trim()) return;
                if ($wrap.length) $wrap.append(error);
                else element.after(error);

                if (element.hasClass('select2-hidden-accessible')) {
                    const select2Id = element.attr('data-select2-id');
                    const $select2Container = $('[aria-labelledby="select2-' + select2Id + '-container"]');
                    $select2Container.focus();
                }
            },
            showErrors: function (errorMap, errorList) {
                this.errorList = errorList;
                this.errorMap = errorMap;
            }
        });

        // --- Core methods
        function check() {
            return $form.valid(); // just validate, don’t show toast
        }

        function errors() {
            if (!validator || !validator.errorList) return [];
            return validator.errorList.sort((a, b) => (domOrderMap[a.element.name] || 0) - (domOrderMap[b.element.name] || 0));
        }

        function resetErrors() {
            if (!validator) return;
            validator.resetForm();
            $form.find('.error').removeClass('error');
        }

        // --- File input validation
        function setupFileInputs() {
            $form.find('input[type="file"]').each(function () {
                const input = this;
                $(input).off(".formMagicChange").on("change.formMagicChange", function () {
                    $(input).valid();
                });
            });
        }
        setupFileInputs();

        // --- MutationObserver for dynamically added file inputs
        const observer = new MutationObserver(mutations => {
            mutations.forEach(mutation => {
                mutation.addedNodes.forEach(node => {
                    if (node.nodeType !== 1) return;

                    const fileInputs = [];
                    if (node.matches?.('input[type="file"]')) fileInputs.push(node);
                    fileInputs.push(...(node.querySelectorAll?.('input[type="file"]') || []));

                    fileInputs.forEach(f => {
                        const maxMb = 5;
                        const allowedTypes = ".png,.jpg,.jpeg,.gif";
                        $(f).rules("add", { fileSize: maxMb * 1024, fileType: allowedTypes });
                        f.addEventListener("change", () => $(f).valid());
                    });
                });
            });
        });
        observer.observe($form[0], { childList: true, subtree: true });

        magic = { check, errors, resetErrors, validator }; // keep reference to real jQuery validator too
        $form.data("formMagicValidator", magic); // use a custom key instead
        return magic;
    };

    global.FormMagicValidation = FormMagicValidation;
})(window, jQuery);


// --- Real-time file validation
$(document).on('change', 'form.form-advance-validation input[type="file"]', function () {
    const $form = $(this).closest('form')[0];
    const magic = FormMagicValidation($form);
    const $input = $(this);

    const maxMb = parseInt($input.data('max-size') || 2) * 1024 * 1024;
    const allowedTypes = $input.attr('accept') || ".png,.jpg,.jpeg,.gif";

    const rules = $input.rules();
    if (!rules.fileSize) $input.rules("add", { fileSize: maxMb });
    if (!rules.fileType) $input.rules("add", { fileType: allowedTypes });

    // just validate, do NOT show toast manually
    if (!$input.valid()) {
        // reset UI if invalid
        setTimeout(() => {
            $input.val('');
            const $card = $input.closest('.upload-file');
            if ($card.length) {
                $card.find('.upload-file-img').hide().attr('src', '');
                $card.find('.upload-file-textbox').show();
                $card.find('.overlay').removeClass('show');
                $card.find('.remove_btn').css('opacity', 0);
                $card[0].classList.remove('input-disabled');
            }
        }, 10);
    }
});

// --- Form submit validation (non-AJAX)
$(document).on('click', '.form-advance-validation.non-ajax-form-validate [type="submit"]', function (event) {
    const $form = $(this).closest('form');
    const validator = FormMagicValidation($form[0]);

    if (!validator.check()) {
        event.preventDefault();

        const errors = validator.errors(); // DOM ordered
        if (!errors.length) return false;

        errors.forEach((e, i) => {
            setTimeout(() => {
                showErrorToast(e.message);
                scrollToErrorField(errors[0].element); // scroll only to first
            }, i * 800); // staggered
        });

        return false;
    }
});

document.addEventListener('DOMContentLoaded', () => {
    // Convert all submit buttons to type="button"
    function initButtons(form) {
        const buttons = form.querySelectorAll('button[type="submit"]');
        buttons.forEach(button => {
            button.type = 'button';
            button.classList.add('button-type-submit');
        });
    }

    const formsAjaxFormValidate = document.querySelectorAll('.form-advance-validation.ajax-form-validate');
    formsAjaxFormValidate.forEach(form => {
        initButtons(form);

        // Add event listener for any input change inside this form
        form.addEventListener('input', () => {
            initButtons(form); // reset buttons whenever input changes
        });

        form.addEventListener('change', () => {
            initButtons(form); // also handle select/file inputs
        });
    });

    // Add click listener
    document.addEventListener('click', function(event) {
        const button = event.target.closest('.form-advance-validation.ajax-form-validate .button-type-submit');
        if (!button) return;

        const form = button.closest('form');
        if (!form) return;

        const validator = FormMagicValidation(form);

        // Validation failed
        if (!validator.check()) {
            event.preventDefault();

            const errors = validator.errors();
            if (!errors.length) return;

            errors.forEach((e, i) => {
                setTimeout(() => {
                    showErrorToast(e.message);
                    if (i === 0) scrollToErrorField(errors[0].element);
                }, i * 800);
            });

            return;
        }

        // Validation passed → submit programmatically
        event.preventDefault();
        button.classList.remove('button-type-submit');
        button.type = 'submit';

        setTimeout(() => {
            button.click();
        }, 50);

        setTimeout(() => {
            button.classList.add('button-type-submit');
            button.type = 'type';
        }, 500);
    });
});


// --- Shared error toast function
function formMagicValidationErrors(magic, errors) {
    if (!magic || !errors || !errors.length) return;

    errors.forEach((e, i) => {
        setTimeout(() => {
            showErrorToast(e.message);
        }, i * 1200);
    });
}

// --- Helper: show toast
function showErrorToast(message) {
    if (typeof toastMagic !== "undefined") {
        toastMagic.error(message, '', true);
    } else if (typeof toastr !== "undefined") {
        toastr.error(message);
    } else {
        alert(message);
    }
}

// --- Scroll to field
async function scrollToErrorField(field) {
    if (!field) return;
    const $field = $(field);
    const $scrollTarget = field.type === "file"
        ? ($field.closest(".error-wrapper").length ? $field.closest(".error-wrapper") : $field)
        : $field;

    const revealPromises = [];
    $scrollTarget.parents().each(function () {
        const $parent = $(this);

        // Tab
        if ($parent.hasClass("tab-pane") && !$parent.hasClass("active")) {
            const id = $parent.attr("id");
            const $tabTrigger = $(`[data-bs-toggle="tab"][href="#${id}"],[data-bs-toggle="tab"][data-bs-target="#${id}"]`);
            if ($tabTrigger.length) {
                $tabTrigger.trigger("click");
                revealPromises.push(new Promise(res => {
                    $parent.one("shown.bs.tab", res);
                    setTimeout(res, 300);
                }));
            }
        }

        // Collapse
        if ($parent.hasClass("collapse") && !$parent.hasClass("show")) {
            const id = $parent.attr("id");
            const $collapseTrigger = $(`[data-bs-toggle="collapse"][data-bs-target="#${id}"]`);
            if ($collapseTrigger.length) {
                $collapseTrigger.trigger("click");
                revealPromises.push(new Promise(res => {
                    $parent.one("shown.bs.collapse", res);
                    setTimeout(res, 300);
                }));
            }
        }

        if ($parent.is(":hidden")) $parent.show();
    });

    await Promise.all(revealPromises);

    setTimeout(() => {
        if ($scrollTarget.is(":visible")) {
            $scrollTarget[0].scrollIntoView({ behavior: "smooth", block: "center" });
            try { field.focus({ preventScroll: true }); } catch {}
        }
    }, 200);
}


// function xhrResponseManager(xhr) {
//     console.log(xhr.responseJSON);
//     if (xhr?.responseJSON && xhr.responseJSON.error) {
//         toastMagic.error(xhr.responseJSON.error);
//     } else if (xhr?.responseJSON && xhr.responseJSON.errors) {
//         $.each(xhr?.responseJSON.errors, function (key, value) {
//             toastMagic.error(value);
//         });
//     } else {
//         toastMagic.error('An unexpected error occurred.');
//     }
// }

// function ajaxResponseManager(response) {
//     if (response.status === "success") {
//         if (response.message) {
//             toastMagic.success(response.message);
//         }
//
//         setTimeout(() => {
//             if (response?.redirectRoute) {
//                 location.href = response.redirectRoute;
//             } else if (response?.redirect_url) {
//                 location.href = response?.redirect_url;
//             }
//         }, 500)
//     } else if (response.status === "error") {
//         if (response.message) {
//             toastMagic.error(response.message);
//         }
//     } else if (response.status === "warning") {
//         if (response.message) {
//             toastMagic.warning(response.message);
//         }
//     }
//
//     if (response.errors) {
//         for (let index = 0; index < response.errors.length; index++) {
//             toastMagic.error(response.errors[index].message, {
//                 CloseButton: true,
//                 ProgressBar: true,
//             });
//         }
//     } else if (response.error) {
//         toastMagic.error(response.error, {
//             CloseButton: true,
//             ProgressBar: true,
//         });
//     }
//
//     if (response?.reload) {
//         location.reload();
//     }
// }
