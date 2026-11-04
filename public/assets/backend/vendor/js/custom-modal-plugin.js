class CustomModalPlugin {
    constructor(options = {}) {
        this.modalId = options.modalId || `custom-modal-${Date.now()}`;
        this.modal = this.createModal(options.customButtons);
        document.body.appendChild(this.modal);
        this.onClose = options.onClose || null;
    }

    createModal(customButtons) {
        const modal = document.createElement("div");
        modal.className = "modal fade custom-modal-view";
        modal.id = this.modalId;
        modal.setAttribute("tabindex", "-1");
        modal.setAttribute("aria-hidden", "true");

        const buttonHTML = customButtons
            ? customButtons
                  .map(
                      btn =>
                          `<button type="button" class="${btn.class}" id="${this.modalId}-${btn.id}">${btn.text}</button>`
                  )
                  .join("")
            : "";

        modal.innerHTML = `
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content shadow-lg">
                    <div class="modal-header border-0 px-2 pt-2 pb-0 d-flex justify-content-end">
                        <button type="button" class="btn-close border-0 btn-circle bg-section2 shadow-none lh-1" data-dismiss="modal" aria-label="Close">
                            <i class="fi fi-sr-cross fs-12"></i>
                        </button>
                    </div>
                    <div class="modal-body px-20 py-0 mb-30">
                    <div class="d-flex flex-column align-items-center text-center mb-30">
                        <img src="" width="80" class="mb-20" id="${this.modalId}-image" alt="">
                        <h2 class="modal-title mb-3" id="${this.modalId}-title"></h2>
                        <div class="text-center" id="${this.modalId}-message"></div>
                    </div>
                        <div class="d-flex justify-content-center gap-3 mt-3">
                            ${buttonHTML}
                            <button type="button" class="btn btn-secondary max-w-120 flex-grow-1" data-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn--primary max-w-120 flex-grow-1" id="${this.modalId}-ok-button" data-dismiss="modal">OK</button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        return modal;
    }

    show({ image, title, message, onConfirm, customCallbacks }) {
        document.getElementById(`${this.modalId}-image`).src = image || "";
        document.getElementById(`${this.modalId}-title`).innerText =
            title || "";
        document.getElementById(`${this.modalId}-message`).innerHTML =
            message || "";

        const okButton = document.getElementById(`${this.modalId}-ok-button`);
        okButton.onclick = () => {
            if (typeof onConfirm === "function") onConfirm();
            this.hide();
        };

        if (customCallbacks) {
            Object.keys(customCallbacks).forEach(id => {
                const button = document.getElementById(`${this.modalId}-${id}`);
                if (button) {
                    button.onclick = customCallbacks[id];
                }
            });
        }

        $(`#${this.modalId}`).modal("show");
    }

    hide() {
        $(`#${this.modalId}`).modal("hide");
        if (typeof this.onClose === "function") {
            this.onClose();
        }
    }
}

// Usage Example
const modalInstances = new Map();

document.querySelector("body").addEventListener("click", function(event) {
    if (event.target.classList.contains("custom-modal-plugin")) {
        event.preventDefault();
        try {
            document.querySelectorAll('.custom-modal-view').forEach((el) => {
                const instance = bootstrap.Modal.getInstance(el);
                if (instance) {
                    instance.hide();
                }
            });
        } catch (e) {
        }

        const modalId = `custom-modal-${Date.now()}`;
        const modal = new CustomModalPlugin({ modalId });
        modalInstances.set(modalId, modal);

        const onImage = event.target.dataset.onImage;
        const offImage = event.target.dataset.offImage;
        const onTitle = event.target.dataset.onTitle;
        const offTitle = event.target.dataset.offTitle;
        const onMessage = event.target.dataset.onMessage;
        const offMessage = event.target.dataset.offMessage;
        const modalType = event.target.dataset.modalType;
        const inputElement = event.target;
        const inputElementForm = document.querySelector(
            event.target.dataset.modalForm
        );

        const isChecked = inputElement.checked;
        const image = isChecked ? onImage : offImage;
        const title = isChecked ? onTitle : offTitle;
        const message = isChecked ? onMessage : offMessage;

        const verification = event.target.dataset.verification;

        if (verification && verification === "firebase-auth") {
            if (!checkFirebaseAuthVerification()) {
                return false;
            }
        }
        const shouldReload = event.target.dataset.reload === "true";
        modal.show({
            image,
            title,
            message,
            onConfirm: () => {
                if (modalType === "input-change") {
                    if (inputElement.type === "checkbox") {
                        inputElement.checked = !inputElement.checked;
                    } else if (
                        inputElement.type === "radio" &&
                        !inputElement.checked
                    ) {
                        inputElement.checked = true;
                    }
                } else if (modalType === "input-change-form") {
                    if (inputElement.type === "checkbox") {
                        inputElement.checked = !inputElement.checked;
                    } else if (
                        inputElement.type === "radio" &&
                        !inputElement.checked
                    ) {
                        inputElement.checked = true;
                    }
                    if (
                        inputElementForm &&
                        inputElementForm.tagName === "FORM"
                    ) {
                        $(inputElementForm).submit();
                    }
                }

                if (shouldReload) {
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                }
            },
            customCallbacks: {
                customAction: () => {
                }
            }
        });
    }
});
