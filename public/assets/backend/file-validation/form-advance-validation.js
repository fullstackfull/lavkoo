"use strict";

/**
 * FormMagicValidation
 * Initialize JustValidate on a form with auto rules for inputs, file validation, and helper methods.
 * @param {HTMLFormElement} form
 * @returns {Object} { check, errors, destroy, validator }
 */
function FormMagicValidation(form) {
    if (!form) return null;

    // Initialize JustValidate
    const validator = new window.JustValidate(form, {
        validateBeforeSubmitting: true,
        lockForm: false,
        focusInvalidField: false,
        errorFieldCssClass: '',
        errorLabelCssClass: 'just-validate-error-label',
        errorLabelStyle: { color: 'red', fontSize: '0.85rem' },
        successFieldCssClass: '',
    });

    // JustValidate auto-binds a submit listener that always calls e.preventDefault().
    // We drive validation manually via validator.validate() from our own submit handler,
    // so unbind JustValidate's listener — otherwise its preventDefault leaves the form
    // in a "defaultPrevented" state and the non-ajax submit path skips native submission.
    if (typeof validator.formSubmitHandler === 'function') {
        form.removeEventListener('submit', validator.formSubmitHandler);
    }

    // Remove all error/success labels dynamically
    const observer = new MutationObserver(() => {
        form.querySelectorAll('.just-validate-error-label, .just-validate-success-label')
            .forEach(el => el.remove());
    });
    observer.observe(form, { childList: true, subtree: true });

    // Optional: live validation observer
    validator.onValidate(({ isValid, isSubmitted, fields }) => {
        // Object.entries(fields)
        // .filter(([_, f]) => !f.isValid)
        // .forEach(([name, fieldState]) => {
        //     if (fieldState.errors && fieldState.errors.length > 0) {
        //         // Show a toast for each error message
        //         fieldState.errors.forEach(msg => showErrorToast(msg));
        //     } else {
        //         // Fallback message
        //         showErrorToast(`${name} is invalid`);
        //     }
        // });

        // Debug individual field states (optional)
        let errorsShowIndex = 0;
        Object.entries(fields).forEach(([name, state]) => {
            if (!state.isValid && state?.errorMessage) {
                setTimeout(() => {
                    // toastMagic.error(state?.errorMessage)
                }, errorsShowIndex * 500);
                errorsShowIndex++;
            }
        });
    });

    // Helper: get field label for error messages
    const getFieldLabel = (input) => input.closest('.form-group')?.querySelector('label')?.textContent?.trim()
        || input.placeholder || 'This field';

    // Auto-add rules to inputs
    form.querySelectorAll('input, textarea, select').forEach(input => {
        if (!input.name || input.type === 'hidden' || input.hasAttribute('hidden')) return;

        const name = input.name;
        if (!name) return;
        const rules = [];
        if (form.classList.contains('form-advance-inputs-validation')) {
            if (input.required && input.type === 'file') rules.push({ rule: 'required', errorMessage: input.dataset.requiredMsg || `${getFieldLabel(input)} is required` });
            if (input.type === 'email') rules.push({ rule: 'email', errorMessage: input.dataset.emailMsg || 'Please enter a valid email address' });
            if (input.type === 'number' || input.inputMode === 'numeric') rules.push({ rule: 'number', errorMessage: input.dataset.numberMsg || 'Please enter a valid number' });
            if (input.type === 'url') rules.push({ rule: 'customRegexp', value: /^https?:\/\/.+/, errorMessage: input.dataset.urlMsg || 'Please enter a valid URL' });
            if (input.minLength) rules.push({ rule: 'minLength', value: parseInt(input.minLength), errorMessage: input.dataset.minlengthMsg || `Minimum ${input.minLength} characters` });
            if (input.maxLength && parseInt(input.maxLength) > 0) rules.push({ rule: 'maxLength', value: parseInt(input.maxLength), errorMessage: input.dataset.maxlengthMsg || `Maximum ${input.maxLength} characters` });
            if (input.min) rules.push({ rule: 'minNumber', value: parseFloat(input.min), errorMessage: input.dataset.minMsg || `Minimum value is ${input.min}` });
            if (input.max) rules.push({ rule: 'maxNumber', value: parseFloat(input.max), errorMessage: input.dataset.maxMsg || `Maximum value is ${input.max}` });
            if (input.pattern) rules.push({ rule: 'customRegexp', value: new RegExp(input.pattern), errorMessage: input.dataset.patternMsg || 'Invalid format' });

            // Standard rules
            // if (input.required && !input.classList.contains('select2-hidden-accessible')) {
            //     rules.push({
            //         rule: 'required',
            //         errorMessage: input.dataset.requiredMsg || `${getFieldLabel(input)} is required`
            //     });
            // }

            // Select2 auto-detect
            if (input.classList.contains('select2-hidden-accessible')) {
                rules.push({
                    rule: 'function',
                    validator: (val, field) => {
                        if (!field) return false;
                        let value = $(field).val();

                        // Ensure value is never undefined
                        if (value === undefined || value === null) return false;

                        // If it's an array (multi-select)
                        if (Array.isArray(value)) return value.length > 0;

                        // If it's a string
                        if (typeof value === 'string') return value.trim() !== '';

                        return false; // fallback for anything else
                    },
                    errorMessage: input.dataset.requiredMsg || `${getFieldLabel(input)} is required`
                });
            }

            // if (name.endsWith('[]')) {
            //     // JustValidate will select all matching inputs
            //     rules.push({
            //         rule: 'function',
            //         validator: (val, field) => {
            //             // Get all inputs with this name
            //             const inputs = form.querySelectorAll(`input[name="${name}"]`);
            //             // Required: at least one filled
            //             return Array.from(inputs).some(i => i.value && i.value.trim() !== '');
            //         },
            //         errorMessage: input.dataset.requiredMsg || `${getFieldLabel(input)} is required`
            //     });
            //     validator.addField(`[name="${name}"]`, rules);
            // } else {
            //     validator.addField(`[name="${name}"]`, rules);
            // }

            if (name.endsWith('[]')) {
                rules.push({
                    rule: 'function',
                    validator: (val, field) => {
                        const inputs = form.querySelectorAll(`input[name="${name}"]`);
                        return Array.from(inputs).some(i => i.value && i.value.trim() !== '');
                    },
                    errorMessage: input.dataset.requiredMsg || `${getFieldLabel(input)} is required`
                });
            }
        }

        if (form.classList.contains('form-advance-file-validation')) {
            // --- File input rules using JustValidate native 'files' rule ---
            if (input.type?.toString() === 'file') {
                // Add required first
                if (input.required) {
                    const accept = (input.getAttribute('accept') || '')
                        .replace(/\|/g, ',')
                        .split(',')
                        .map(t => t.trim())
                        .filter(Boolean);

                    const allowedExtensions = accept
                        .filter(a => a.startsWith('.'))
                        .map(a => a.replace('.', ''));

                    const allowedTypes = accept
                        .filter(a => a.includes('/'))
                        .map(a => a.toLowerCase());

                    const maxSizeMb = parseInt(input.dataset.maxSize, 10);
                    const maxSizeBytes = !isNaN(maxSizeMb) ? maxSizeMb * 1024 * 1024 : 5 * 1024 * 1024;

                    rules.push({
                            rule: 'minFilesCount',
                            value: 1,
                            errorMessage: input.dataset.requiredMsg || `${getFieldLabel(input)} is required`,
                        },
                        // {
                        // rule: 'files',
                        // value: {
                        //     files: {
                        //         extensions: allowedExtensions.length ? allowedExtensions : undefined,
                        //         types: allowedTypes.length ? allowedTypes : undefined,
                        //         maxSize: maxSizeBytes, // convert MB to bytes
                        //     },
                        // },
                        // errorMessage: input.dataset.fileMsg || `${getFieldLabel(input)} is invalid`,
                        // }
                    );
                }

                // Optional: min/max files count (if you want to add)
                if (input.dataset.minFiles) {
                    rules.push({
                        rule: 'minFilesCount',
                        value: parseInt(input.dataset.minFiles, 10),
                        errorMessage: `Select at least ${input.dataset.minFiles} file(s)`
                    });
                }
                if (input.dataset.maxFiles) {
                    rules.push({
                        rule: 'maxFilesCount',
                        value: parseInt(input.dataset.maxFiles, 10),
                        errorMessage: `Select at most ${input.dataset.maxFiles} file(s)`
                    });
                }

            }
        }

        if (rules.length > 0) {
            validator.addField(`[name="${name}"]`, rules);
        }

        if (!rules.length) {
            console.warn(`Skipping "${name}" — no validation rules detected.`);
            return;
        }
    });

    return {
        /** Run validation and return boolean */
        async check() {
            try {
                this.lastResult = await validator.validate(); // save it
                return this.lastResult?.isValid === true;
            } catch (e) {
                console.warn(e);
                return false;
            }
        },

        errors() {
            const list = [];

            // ✅ Get fields from validator instance, not lastResult
            const fields = validator.fields;

            if (!fields) return list;

            // Convert numeric keys (1, 2, 3) to entries
            Object.entries(fields).forEach(([id, fieldState]) => {
                if (!fieldState.isValid) {
                    const message = fieldState.errorMessage ||
                        (fieldState.errors?.[0]) ||
                        `Field is invalid`;

                    list.push({
                        element: fieldState.elem,
                        message: message,
                        fieldName: fieldState.elem?.name || id
                    });
                }
            });

            return list;
        },

        /** Destroy observer and validator */
        destroy() {
            observer.disconnect();
            validator.destroy?.();
        },

        /** Expose JustValidate instance */
        validator
    };
}

// --- Global form initialization ---
const formValidators = new WeakMap();
const formSubmitting = new WeakSet();

function initFormValidation() {
    document.querySelectorAll('.form-advance-validation').forEach(form => {
        if (!formValidators.has(form)) {
            formValidators.set(form, FormMagicValidation(form));
        }
    });
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initFormValidation);
else initFormValidation();

if (typeof MutationObserver !== 'undefined') {
    new MutationObserver(() => initFormValidation()).observe(document.body, { childList: true, subtree: true });
}

// --- Non-AJAX form submit handling ---
document.addEventListener('submit', async function (e) {
    const form = e.target.closest('.form-advance-validation.non-ajax-form-validate');
    if (!form) return;

    e.preventDefault();
    if (formSubmitting.has(form)) return false;

    const validator = formValidators.get(form);
    if (!validator) return true;

    formSubmitting.add(form);

    const valid = await validator.check(); // await result
    const errors = validator.errors();     // only works if you store lastResult inside check

    if (!valid && errors?.length > 0) {
        validator.errors().forEach((err, i) => setTimeout(() => showErrorToast(err.message), i * 400));
        formSubmitting.delete(form);
        return false;
    }

    formSubmitting.delete(form);
    HTMLFormElement.prototype.submit.call(form);
});

// --- Toast helper ---
function showErrorToast(msg) {
    if (typeof toastMagic !== 'undefined'){
        toastMagic.error(msg, '', true);
    } else if(typeof toastr !== 'undefined'){
        toastr.error(msg, '', true);
    } else{
        console.warn(msg);
    }
}


/**
 * Form validation helper
 * @param {HTMLFormElement|jQuery} formElement
 * @returns {Promise<boolean>} true if valid, false if invalid (and shows errors)
 */
async function validateFormHelper(formElement) {
    const form = formElement instanceof jQuery ? formElement : $(formElement);
    // Prevent double submission
    if (formSubmitting.has(form)) return false;
    const validator = formValidators.get(form[0]); // always get native DOM element
    if (!validator) return true;
    formSubmitting.add(form);

    const valid = await validator.check();

    if (!valid && validator.errors()?.length > 0) {
        validator.errors().forEach((err, i) => setTimeout(() => showErrorToast(err.message), i * 400));
        formSubmitting.delete(form);
        return false;
    }

    return true;
}


/**
 * Form helper to cleanup after submission
 * @param {jQuery|HTMLFormElement} formElement
 */
function formSubmitCleanup(formElement) {
    const form = formElement instanceof jQuery ? formElement : $(formElement);
    formSubmitting.delete(form);
}


document.querySelectorAll('.form-advance-validation input[type="file"]').forEach(input => {
    input.addEventListener('change', function () {

        if (this.type !== 'file') return true;

        const files = this.files;
        const required = this.required;
        const maxSizeMb = parseInt(this.dataset.maxSize || 3, 10);
        const accept = (this.getAttribute('accept') || '')
            .split(',')
            .map(t => t.trim().toLowerCase())
            .filter(Boolean);

        // ✅ Clear any previous state or message if needed
        let isValid = true;

        // 🧩 Required check
        if (required && (!files || files.length === 0)) {
            isValid = false;
            showErrorToast(`${getFieldLabel(this)} is required`);
            removeInputConnectedElements(this);
            return;
        }

        // Skip if optional and empty
        if (!files || files.length === 0) return;

        for (const f of files) {
            // 🧩 Size check
            if (f.size > maxSizeMb * 1024 * 1024) {
                isValid = false;
                showErrorToast(`${f.name} exceeds ${maxSizeMb}MB`);
                removeInputConnectedElements(this);
                break;
            }

            // 🧩 Type check
            if (
                accept.length &&
                !accept.some(a =>
                    a.startsWith('.')
                        ? f.name.toLowerCase().endsWith(a)
                        : f.type.toLowerCase() === a
                )
            ) {
                isValid = false;
                showErrorToast(`${f.name} has an invalid file type`);
                removeInputConnectedElements(this);
                break;
            }
        }

        // 🚫 Reset the input if invalid
        if (!isValid) {
            this.value = ''; // clear selected file(s)
        }
    });
});

// ✅ Helper: extract label
function getFieldLabel(input) {
    return (
        input.dataset.label ||
        input.name ||
        input.id ||
        'This field'
    ).replace(/[_\[\]]+/g, ' ').trim();
}

function removeInputConnectedElements(input) {
    const container = input.closest('.custom_upload_input');
    if (container) {
        const deleteSections = container.querySelectorAll('.delete_file_input_section');
        deleteSections.forEach(section => section.classList.add('d-none'));
    }

    const cardContainer = input.closest('.upload-file');
    if (cardContainer) {
        setTimeout(() => {
            const uploadImgs = cardContainer.querySelectorAll('.upload-file-img');
            const textboxes = cardContainer.querySelectorAll('.upload-file-textbox');
            const overlays = cardContainer.querySelectorAll('.overlay');
            const removeBtns = cardContainer.querySelectorAll('.remove_btn');

            uploadImgs.forEach(img => {
                img.style.display = 'none';
                img.src = '';
            });

            textboxes.forEach(box => {
                box.style.display = '';
            });

            overlays.forEach(overlay => {
                overlay.classList.remove('show');
            });

            removeBtns.forEach(btn => {
                btn.style.opacity = 0;
            });

            cardContainer.classList.remove('input-disabled');
        }, 20)
    }

    let uploadZone = input.closest('.upload-zone');
    if (uploadZone) {
        setTimeout(() => {
            const textboxes = uploadZone.querySelectorAll('.text-box');

            textboxes.forEach(box => {
                box.style.display = '';
                box.classList.remove('d-none');
            });

        }, 20)
    }
}


function xhrResponseManager(xhr) {
    if (xhr?.responseJSON && xhr.responseJSON.error) {
        toastMagic.error(xhr.responseJSON.error);
    } else if (xhr?.responseJSON && xhr.responseJSON.errors) {
        $.each(xhr?.responseJSON.errors, function (key, value) {
            toastMagic.error(value);
        });
    } else {
        toastMagic.error('An unexpected error occurred.');
    }
}
function ajaxResponseManager(response) {
    if (response.status === "success") {
        if (response.message) {
            toastMagic.success(response.message);
        }

        setTimeout(() => {
            if (response?.redirectRoute) {
                location.href = response.redirectRoute;
            } else if (response?.redirect_url) {
                location.href = response?.redirect_url;
            }
        }, 500)
    } else if (response.status === "error") {
        if (response.message) {
            toastMagic.error(response.message);
        }
    } else if (response.status === "warning") {
        if (response.message) {
            toastMagic.warning(response.message);
        }
    }

    if (response.errors) {
        for (let index = 0; index < response.errors.length; index++) {
            toastMagic.error(response.errors[index].message, {
                CloseButton: true,
                ProgressBar: true,
            });
        }
    } else if (response.error) {
        toastMagic.error(response.error, {
            CloseButton: true,
            ProgressBar: true,
        });
    }

    if (response?.reload) {
        location.reload();
    }
}
