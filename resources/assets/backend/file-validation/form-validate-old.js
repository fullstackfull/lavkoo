$(function () {
    function showErrorToast(message) {
        if (typeof toastMagic !== "undefined") {
            if (typeof toastr !== "undefined" && toastr.clear) {
                toastr.clear();
            }
            
            const existingToasts = document.querySelectorAll('.toast-item');
            existingToasts.forEach(toast => toast.remove());
            
            toastMagic.error(message);
        } else if (typeof toastr !== "undefined") {
            toastr.clear();
            toastr.error(message);
        } else {
            alert(message); 
        }
    }

    // --- Custom rules
    $.validator.addMethod("fileSize", function (value, element, maxSize) {
        if (!element.files || element.files.length === 0) return true;

        for (let i = 0; i < element.files.length; i++) {
            if (element.files[i].size > maxSize) {
                const $element = $(element);
                const $card = $element.closest('.upload-file');
                
                const previousFileData = element.dataset.previousFileData;
                const previousFileName = element.dataset.previousFileName;
                
                element.value = '';
                
                if ($card.length) {
                    setTimeout(function() {
                        if (previousFileData && previousFileName) {
                            // Restore previous file preview
                            const imgElement = $card.find('.upload-file-img')[0];
                            const textbox = $card.find('.upload-file-textbox')[0];
                            const overlay = $card.find('.overlay')[0];
                            const removeBtn = $card.find('.remove_btn')[0];
                            
                            if (imgElement) {
                                imgElement.src = previousFileData;
                                imgElement.style.display = 'block';
                            }
                            if (textbox) textbox.style.display = 'none';
                            if (overlay) overlay.classList.add('show');
                            if (removeBtn) removeBtn.style.opacity = 1;
                            $card[0].classList.add('input-disabled');
                        } else {
                            if (typeof resetFileUpload === 'function') {
                                resetFileUpload($card[0]);
                            } else {
                                const textbox = $card.find('.upload-file-textbox')[0];
                                const imgElement = $card.find('.upload-file-img')[0];
                                const overlay = $card.find('.overlay')[0];
                                const removeBtn = $card.find('.remove_btn')[0];
                                
                                if (imgElement) {
                                    imgElement.style.display = 'none';
                                    imgElement.src = '';
                                }
                                if (textbox) textbox.style.display = 'block';
                                if (overlay) overlay.classList.remove('show');
                                if (removeBtn) removeBtn.style.opacity = 0;
                                $card[0].classList.remove('input-disabled');
                            }
                        }
                    }, 10);
                }
                return false;
            }
        }
        return true;
    }, function (params, element) {
        return $("#text-validate-translate").data("file-size-larger");
    });

    $.validator.addMethod("fileType", function (value, element) {
        if (!element.files || element.files.length === 0) return true;

        const acceptAttr = element.getAttribute("accept");
        if (!acceptAttr) return true;

        const acceptedTypes = acceptAttr.split(',')
            .map(type => type.trim().toLowerCase())
            .filter(Boolean);

        for (let i = 0; i < element.files.length; i++) {
            const file = element.files[i];
            const fileName = file.name.toLowerCase();
            const fileType = file.type.toLowerCase();

            const isValid = acceptedTypes.some(acc => {
                if (acc.startsWith('.')) {
                    return fileName.endsWith(acc);
                } else if (acc.endsWith('/*')) {
                    const base = acc.replace('/*', '');
                    return fileType.startsWith(base);
                } else {
                    return fileType === acc;
                }
            });

            if (!isValid) {
                const $element = $(element);
                const $card = $element.closest('.upload-file');

                element.value = '';

                if ($card.length) {
                    setTimeout(function() {
                        if (typeof resetFileUpload === 'function') {
                            resetFileUpload($card[0]);
                        } else {
                            const textbox = $card.find('.upload-file-textbox')[0];
                            const imgElement = $card.find('.upload-file-img')[0];
                            const overlay = $card.find('.overlay')[0];
                            const removeBtn = $card.find('.remove_btn')[0];
                            
                            if (imgElement) {
                                imgElement.style.display = 'none';
                                imgElement.src = '';
                            }
                            if (textbox) textbox.style.display = 'block';
                            if (overlay) overlay.classList.remove('show');
                            if (removeBtn) removeBtn.style.opacity = 0;
                            $card[0].classList.remove('input-disabled');
                        }
                    }, 10);
                }

                showErrorToast($("#text-validate-translate").data("file-type-not-allowed") || "Invalid file type selected");
                return false;
            }
        }

        return true;
    }, function (params, element) {
        return $("#text-validate-translate").data("file-type-not-allowed");
    });


    $.validator.addMethod("strongPassword", function (value, element) {
        return this.optional(element) ||
            /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{8,}$/.test(value);
    });

    $.validator.addMethod("maxTextLength", function(value, element, max) {
        if (!value) return true; 
        return value.length <= max;
    }, function(params, element) {
        return $("#text-validate-translate").data("max-limit-crossed");
    });

    // --- scroll to error field
    async function scrollToErrorField(field) {
        if (!field) return;

        const $field = $(field);
        const $scrollTarget = field.type === "file"
            ? ($field.closest(".error-wrapper").length ? $field.closest(".error-wrapper") : $field)
            : $field;

        const revealPromises = [];

        $scrollTarget.parents().each(function () {
            const $parent = $(this);
            const parentClasses = $parent[0].classList;

            // --- Tab ---
            if ($parent.hasClass("tab-pane") && !$parent.hasClass("active")) {
                const id = $parent.attr("id");
                const $tabTrigger = $(
                    `[data-bs-toggle="tab"][href="#${id}"],[data-bs-toggle="tab"][data-bs-target="#${id}"],` +
                    `[data-toggle="tab"][href="#${id}"],[data-toggle="tab"][data-target="#${id}"]`
                );
                if ($tabTrigger.length) {
                    $tabTrigger.trigger("click");
                    revealPromises.push(new Promise(res => {
                        $parent.one("shown.bs.tab", res);
                        setTimeout(res, 300);
                    }));
                }
            }

            // --- Collapse ---
            if ($parent.hasClass("collapse") && !$parent.hasClass("show")) {
                const id = $parent.attr("id");
                const $collapseTrigger = $(
                    `[data-bs-toggle="collapse"][data-bs-target="#${id}"],` +
                    `[data-toggle="collapse"][data-target="#${id}"]`
                );
                if ($collapseTrigger.length) {
                    $collapseTrigger.trigger("click");
                    revealPromises.push(new Promise(res => {
                        $parent.one("shown.bs.collapse", res);
                        setTimeout(res, 300);
                    }));
                }
            }

            // --- Custom Lang Tab ---
            if ($parent.hasClass("lang_form") && $parent.hasClass("d-none")) {
                $(".lang_form").addClass("d-none");
                $(".lang_link").removeClass("active");
                const tabId = $parent.attr("id");
                $("#" + tabId).removeClass("d-none");
                $("#" + tabId.replace("-form", "-link")).addClass("active");
                revealPromises.push(Promise.resolve());
            }

            // --- Hidden div show ---
            if ($parent.is(":hidden")) $parent.show();
        });

        await Promise.all(revealPromises);

        setTimeout(() => {
            if ($scrollTarget.is(":visible")) {
                $scrollTarget[0].scrollIntoView({ behavior: "smooth", block: "center" });
                try { field.focus({ preventScroll: true }); } catch (e) {}
            }
        }, 200);
    }


    $(".custom-validation").each(function () {
        let $form = $(this);
        let fileRules = {};
        // let defaultRules = {};
        
        $form.find("input, textarea, select").each(function (i) {
            const $field = $(this);
            const rawName = $field.attr("name");
            if (!rawName) return;

            let uniqueName = rawName;
            if (/\[\]$/.test(rawName)) uniqueName = rawName.replace(/\[\]$/, `[${i}]`);

            $field.attr("data-orig-name", rawName);   
            $field.attr("data-unique-name", uniqueName);
            
            if ($field.attr("type") === "file") {
                let bytes = 2 * 1024 * 1024;
                const maxSize = $field.data("max-size");
                if (maxSize) {
                    const value = Number(maxSize);
                    if (!isNaN(value)) bytes = value * 1024 * 1024;
                }

                fileRules[rawName] = { fileSize: bytes, fileType: true };

            }
        });

        $form.validate({
            ignore: ":hidden:not(textarea[required])",
            rules: $.extend({}, fileRules),
            onfocusout: false,
            onkeyup: false,
            onclick: false,
            messages: {
                email: { email: "Please enter a valid email" },
                password: { 
                    required: $("#text-validate-translate").data("required"),
                    strongPassword: $("#text-validate-translate").data("password-validation")
                },
                confirmPassword: { 
                    required: $("#text-validate-translate").data("required"),
                    equalTo: $("#text-validate-translate").data("passwords-do-not-match")
                }
            },

            errorPlacement: function(error, element) {
                var $wrap = element.closest('.error-wrapper');
                if (!error.text().trim()) return;

                if ($wrap.length) $wrap.append(error);
                else element.after(error);
            },

            showErrors: function(errorMap, errorList) {
                this.errorList = errorList;
                this.errorMap = errorMap;

                if (errorList.length) {
                    const error = errorList[0];
                    const el = error.element;
                    const $el = $(el);
                    let msg = error.message;

                    if (error.method === "required") {
                        if ($el.data('required-msg')) {
                            msg = $el.data('required-msg');
                        } else {
                            msg = $("#text-validate-translate").data("required-field") || msg;
                        }
                    }

                    showErrorToast(msg);
                    scrollToErrorField(el);
                }
            },
            
            invalidHandler: function (event, validator) {
                if (validator.errorList.length) {
                    scrollToErrorField(validator.errorList[0].element);
                }
            },
            
            submitHandler: function(form) {
                let $form = $(form);
                if ($form.data("ajax") === true) {
                    let formData = new FormData(form);
                    $.ajax({
                        url: form.action,
                        type: form.method,
                        data: formData,
                        processData: false,
                        contentType: false,
                        success: function(response) {
                            $('#answers').html(response);
                        },
                        error: function(xhr) {
                            alert($("#text-validate-translate").data("something-went-wrong"));
                        }
                    });
                } else {
                    form.submit();
                }
            }
        });

        $form.find("input, textarea, select").each(function () {
            const $field = $(this);
            let type = $field.attr("type") || ($field.is("textarea") ? "textarea" : "select");

            if ($field.prop("required")) $field.rules("add", { required: true });

            if (type === "text" || $field.is("textarea")) {
                let maxLen = $field.is("[maxlength]") ? parseInt($field.attr("maxlength"), 10) : NaN;

                if (!isNaN(maxLen) && maxLen > 0) {
                    $field.rules("add", { maxTextLength: maxLen });
                }
                $field.on("input", function() {
                    $form.validate().element(this);
                });
            }

            if (type === "email") $field.rules("add", { email: true });

            if (type === "password") $field.rules("add", { strongPassword: true });

            if ($field.attr("name") === "confirmPassword") {
                const $mainPassword = $form.find('input[type="password"]').not('[name="confirmPassword"]').first();
                if ($mainPassword.length) $field.rules("add", { equalTo: $mainPassword });
            }

            if ($field.is("select.select2-hidden-accessible")) {
                $field.on("change.select2", function () {
                    $form.validate().element(this);
                });
            }
        });


        $form.find('input[type="file"]').on('change', function () {
            if (this.files && this.files.length > 0) {
                $form.validate().element(this);
            }
        });

        $form.on("reset", function () {
            $form.validate().resetForm();
        });
    });

});
