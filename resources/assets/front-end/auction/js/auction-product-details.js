document.addEventListener("click", function (e) {
    const target = e.target.closest(".share-on-social-media");
    if (!target) return;

    e.preventDefault();

    const social = target.dataset.socialMediaName;
    const url = target.dataset.action;

    const width = 600;
    const height = 400;
    const left = (window.screen.width - width) / 2;
    const top = (window.screen.height - height) / 2;

    window.open(
        "https://" + social + encodeURIComponent(url),
        "Popup",
        `toolbar=0,status=0,width=${width},height=${height},left=${left},top=${top}`
    );
});

$(document).on("click", ".auction-saved-update", function () {
    let btn = $(this);
    let url = btn.data("url");
    let auctionProductId = btn.data("auction-product-id");

    $.ajaxSetup({
        headers: {
            "X-CSRF-TOKEN": $('meta[name="_token"]').attr("content"),
        },
    });

    $.ajax({
        url: url,
        method: "POST",
        data: { auction_product_id: auctionProductId },
        success: function (data) {
            let isSaved = data?.is_saved;
            let newTitle = isSaved ? btn.data("saved-title") : btn.data("unsaved-title");

            btn.toggleClass("is-saved", isSaved);
            btn.find(".auction-saved-count").text(data?.saved_count ?? 0);
            btn.attr("data-bs-title", newTitle);

            let tooltip = bootstrap.Tooltip.getInstance(btn[0]);
            if (tooltip) {
                tooltip.setContent({ ".tooltip-inner": newTitle });
            }

            if (data?.message) {
                toastr.success(data.message);
            }
        },
        error: function (xhr) {
            if (xhr.status === 401) {
                $("#login-alert-modal").modal("show");
            }
        },
    });
});

function updateCartOptions(html) {
    const $container = $('#product-cart-option-container');
    $container.html(html);
    $container.find('[data-bs-toggle="tooltip"]').each(function () {
        new bootstrap.Tooltip(this);
    });
}

function updateProfileAuctionInfo(html) {
    const $old = $('#profile-auction-info-container');
    if (!$old.length || !html) return;
    const $new = $(html);
    $old.replaceWith($new);
    $new.find('[data-bs-toggle="tooltip"]').each(function () {
        new bootstrap.Tooltip(this);
    });
}

function updateAuctionProgress() {
    const $bar = $(".auction-progress-bar");
    if (!$bar.length) return;

    const start = parseInt($bar.data("start"));
    const end = parseInt($bar.data("end"));
    const now = Math.floor(Date.now() / 1000);

    if (!start || !end || end <= start) return;

    const remaining = end - now;
    const total = end - start;
    const width = Math.min(100, Math.max(0, (remaining / total) * 100)).toFixed(4);

    $bar.css("width", width + "%").attr("aria-valuenow", width);
}

$(function () {
    if ($(".auction-progress-bar").length) {
        updateAuctionProgress();
        setInterval(updateAuctionProgress, 1000);
    }
});

function updateBidList(myBidsOnly = false) {
    const $container = $("#bid-list-container");
    const insightContainer = $("#insight-container");
    const url = $container.data("url");
    const errorMessage = $container.data("error-message");

    const minHeight = $container.height();
    if (minHeight > 0) {
        $container.css('min-height', minHeight + 'px');
    }
    
    $container.stop(true, false).animate({ opacity: 0 }, 150, function() {
        $container.html('<div class="text-center py-4"><span class="spinner-border spinner-border-sm"></span></div>').animate({ opacity: 1 }, 150);
    });

    if (insightContainer.length) {
        const insightMinHeight = insightContainer.height();
        if (insightMinHeight > 0) {
            insightContainer.css('min-height', insightMinHeight + 'px');
        }
        insightContainer.stop(true, false).animate({ opacity: 0 }, 150);
    }

    $.ajax({
        url: url,
        method: "GET",
        data: myBidsOnly ? { my_bids_only: 1 } : {},
        success: function (data) {
            $container.stop(true, false).hide().css('opacity', 1).html(data.html).fadeIn(500, function() {
                $container.css('min-height', '');
            });

            if (insightContainer && data?.insightHtml) {
                insightContainer.stop(true, false).hide().css('opacity', 1).html(data.insightHtml).fadeIn(500, function() {
                    insightContainer.css('min-height', '');
                });

                insightContainer.find('[data-bs-toggle="tooltip"]').each(function () {
                    new bootstrap.Tooltip(this);
                });
            }
        },
        error: function () {
            $container.stop(true, false).hide().css('opacity', 1).html('<div class="text-center py-4 text-danger">' + errorMessage + '</div>').fadeIn(500, function() {
                $container.css('min-height', '');
            });
            if (insightContainer.length) {
                insightContainer.stop(true, false).animate({ opacity: 1 }, 500, function() {
                    insightContainer.css('min-height', '');
                });
            }
        },
    });
}

$(document).on("change", "#myBidonly", function () {
    updateBidList(this.checked);
});

// --- Realtime refresh when the current user is outbid (Firebase notification) ---
// Reuses the exact fragments rendered after a bid is placed, so no rendering logic
// is duplicated:
//   • Live Bidding Tab  -> updateBidList() (GET auction.product.bid-list)
//   • Main Auction Card -> GET auction.product.card-html, applied through the existing
//                          updateCartOptions()/updateProfileAuctionInfo() helpers.
function refreshAuctionCard() {
    const cardUrl = $("#bid-list-container").data("card-url");
    if (!cardUrl) return;

    $.ajax({
        url: cardUrl,
        method: "GET",
        success: function (data) {
            if (data?.detailsCartOptions) {
                updateCartOptions(data.detailsCartOptions);
            }
            if (data?.profileAuctionInfoHtml) {
                updateProfileAuctionInfo(data.profileAuctionInfoHtml);
            }
        },
    });
}

function refreshAuctionDetails() {
    // Live Bidding Tab — preserve the current "My Bids Only" toggle state.
    updateBidList($("#myBidonly").is(":checked"));
    // Main Auction Card — highest bid, leading status, rankings, summary.
    refreshAuctionCard();
}

// Called by auction-notification-listener.js on an `auction_outbid` push. Refreshes only
// when the notification targets the auction currently on screen.
window.auctionRefreshOnOutbid = function (auctionId) {
    const currentId = String($("#bid-list-container").data("auction-id") || "");
    if (!currentId || String(auctionId) !== currentId) return;
    refreshAuctionDetails();
};

function submitBidWithdraw(btn) {
    const url = btn.data("url");
    const auctionProductId = btn.data("auction-product-id");
    const $spinner = $('<span class="spinner-border spinner-border-sm me-1"></span>');

    btn.prop("disabled", true).prepend($spinner);

    $.ajaxSetup({
        headers: { "X-CSRF-TOKEN": $('meta[name="_token"]').attr("content") },
    });

    $.ajax({
        url: url,
        method: "POST",
        data: { auction_product_id: auctionProductId },
        success: function (data) {
            if (data.status === 1) {
                toastr.success(data.message);
                updateBidList();
            } else {
                toastr.error(data.message);
            }

            if (data?.detailsCartOptions) {
                updateCartOptions(data.detailsCartOptions);
            }

            if (data?.profileAuctionInfoHtml) {
                updateProfileAuctionInfo(data.profileAuctionInfoHtml);
            }
        },
        error: function (xhr) {
            if (xhr.status === 401) {
                $("#login-alert-modal").modal("show");
            } else if (xhr.responseJSON?.message) {
                toastr.error(xhr.responseJSON.message);
            } else {
                toastr.error(btn.data("error-message"));
            }
        },
        complete: function () {
            btn.prop("disabled", false);
            $spinner.remove();
        },
    });
}

$(document).on("click", ".place-bid-withdraw-btn", function () {
    const btn = $(this);

    if (typeof Swal === "undefined") {
        submitBidWithdraw(btn);
        return;
    }

    Swal.fire({
        title: btn.data("confirm-title"),
        text: btn.data("confirm-message"),
        icon: "warning",
        showCancelButton: true,
        confirmButtonColor: "#d33",
        cancelButtonColor: "#6c757d",
        confirmButtonText: btn.data("confirm-button"),
        cancelButtonText: btn.data("cancel-button"),
        reverseButtons: true,
    }).then(function (result) {
        if (result.isConfirmed) {
            submitBidWithdraw(btn);
        }
    });
});

$(document).on("click", ".place-bid-open-modal-btn", function () {
    const btn = $(this);
    const url = btn.data("url");
    const $modalContent = $("#auction-bid-place-modal .modal-content");
    const $spinner = $('<span class="spinner-border spinner-border-sm me-1"></span>');

    btn.prop("disabled", true).prepend($spinner);

    $.ajax({
        url: url,
        method: "GET",
        success: function (response) {
            if (response.error) {
                toastr.error(response.error);
                return;
            }

            const shouldShowEntryFeeModal = response?.payment_modal_show === true || response?.payment_modal_show?.toString() === 'true';
            if (response?.status?.toString() === '0' && shouldShowEntryFeeModal) {
                const $entryFeeModal = $('#modal-participate-entry-info');
                if ($entryFeeModal.length) {
                    if (!$entryFeeModal.hasClass('show')) {
                        $entryFeeModal.modal('show');
                    }
                } else {
                    toastr.error(response?.message ?? btn.data("error-message"));
                }
            } else {
                $modalContent.html(response.html);
                $("#auction-bid-place-modal").modal("show");
            }
        },
        error: function (xhr) {
            const message = xhr.responseJSON?.error ?? btn.data("error-message");
            toastr.error(message);
        },
        complete: function () {
            btn.prop("disabled", false);
            $spinner.remove();
        },
    });
});

$(document).on("click", ".place-bid-submit-btn", function () {
    const btn = $(this);
    const url = btn.data("url");
    const auctionProductId = btn.data("auction-product-id");
    const bidAmount = $(".place-bid-amount-input").val();

    if (!bidAmount || isNaN(bidAmount) || parseFloat(bidAmount) <= 0) {
        toastr.error(btn.data("invalid-amount-message"));
        return;
    }

    btn.prop("disabled", true).find("i").replaceWith('<span class="spinner-border spinner-border-sm"></span>');

    $.ajaxSetup({
        headers: { "X-CSRF-TOKEN": $('meta[name="_token"]').attr("content") },
    });

    $.ajax({
        url: url,
        method: "POST",
        data: { auction_product_id: auctionProductId, bid_amount: bidAmount },
        success: function (data) {
            if (data.status === 1) {
                toastr.success(data.message);
                $("#auction-bid-place-modal").modal("hide");
                updateBidList();
                $("#myBidonly-container").removeClass("d-none");
            } else {
                toastr.error(data.message);
            }

            if (data?.detailsCartOptions) {
                updateCartOptions(data.detailsCartOptions);
            }

            if (data?.profileAuctionInfoHtml) {
                updateProfileAuctionInfo(data.profileAuctionInfoHtml);
            }
        },
        error: function (xhr) {
            if (xhr.status === 401) {
                $("#login-alert-modal").modal("show");
            } else if (xhr.responseJSON?.message) {
                toastr.error(xhr.responseJSON.message);
            } else if (xhr.responseJSON?.errors) {
                xhr.responseJSON.errors.forEach(function (err) {
                    toastr.error(err.message);
                });
            } else {
                toastr.error(btn.data("error-message"));
            }
        },
        complete: function () {
            btn.prop("disabled", false).find("span.spinner-border").replaceWith('<i class="fi fi-sr-auction"></i>');
        },
    });
});

$(document).on("click", ".place-bid-preset-btn", function () {
    const amount = $(this).data("amount");
    $(".place-bid-preset-btn").removeClass("active");
    $(this).addClass("active");
    $(".place-bid-amount-input").val(amount).trigger("input");
});

$(document).on("click", ".place-bid-increment-btn", function () {
    const increment = parseFloat($(this).data("amount")) || 0;
    const $input = $(".place-bid-amount-input");
    const rawVal = $input.val().trim();
    const current = parseFloat(rawVal);
    const minBid = parseFloat($input.attr("min")) || 0;
    let newValue;
    if (rawVal === '' || isNaN(current) || current < minBid) {
        newValue = minBid;
    } else {
        newValue = parseFloat((current + increment).toFixed(2));
    }
    $input.val(newValue).trigger("input");
});

$(document).on("click", ".place-bid-decrement-btn", function () {
    const decrement = parseFloat($(this).data("amount")) || 0;
    const $input = $(".place-bid-amount-input");
    const current = parseFloat($input.val()) || 0;
    const minBid = parseFloat($input.attr("min")) || 0;
    const newValue = parseFloat((current - decrement).toFixed(2));
    if (newValue >= minBid) {
        $input.val(newValue).trigger("input");
    }
});

$(document).on("input", ".place-bid-amount-input", function () {
    const $input = $(this);
    const value = $input.val();

    $(".place-bid-preset-btn").each(function () {
        const match = String($(this).data("amount")) === String(value);
        $(this).toggleClass("active", match);
    });

    const $submitBtn = $(".place-bid-submit-btn").first();
    if (!$submitBtn.length) return;

    const rollbackAvailable = String($submitBtn.data("rollback-available")) === "1";
    const minBid = parseFloat($input.attr("min")) || 0;
    const numericValue = parseFloat(value);
    const atRollbackLimit = rollbackAvailable
        && !isNaN(numericValue)
        && numericValue <= minBid;

    const $info = $(".place-bid-rollback-info");
    if ($info.length) {
        const isVisible = $info.is(":visible");
        if (atRollbackLimit && !isVisible) {
            $info.stop(true, true).slideDown(1000);
        } else if (!atRollbackLimit && isVisible) {
            $info.stop(true, true).slideUp(1000);
        }
    }

    const $label = $(".place-bid-submit-label");
    if ($label.length) {
        const placeLabel = $submitBtn.data("label");
        const updateLabel = $submitBtn.data("update-label");
        $label.text(atRollbackLimit ? updateLabel : placeLabel);
    }
});

// Auto-open modals based on URL query params
$(document).ready(function () {
    const params = new URLSearchParams(window.location.search);
    const deepLinkParams = ["open_bid", "open_participate", "open_entry", "open_withdraw", "open_commission"];
    const hasDeepLink = deepLinkParams.some(function (key) { return params.has(key); });

    if (params.get("open_bid") === "1") {
        const $bidBtn = $(".place-bid-open-modal-btn").first();
        if ($bidBtn.length) {
            $bidBtn.trigger("click");
        } else {
            const $participateBtn = $(".auction-participate-btn").first();
            if ($participateBtn.length) {
                $participateBtn.trigger("click");
            }
        }
    }

    if (params.get("open_participate") === "1" || params.get("open_entry") === "1") {
        const $btn = $(".auction-participate-btn").first();
        if ($btn.length) {
            $btn.trigger("click");
        }
    }

    if (params.get("open_withdraw") === "1") {
        const $btn = $('[data-bs-target="#auction-customer-withdraw-offcanvas"]').first();
        if ($btn.length) {
            $btn.trigger("click");
        }
    }

    if (params.get("open_commission") === "1") {
        const $btn = $('[data-bs-target="#auction-cod-commission-modal"]').first();
        if ($btn.length) {
            $btn.trigger("click");
        }
    }

    if (hasDeepLink) {
        deepLinkParams.forEach(function (key) { params.delete(key); });
        const cleanSearch = params.toString();
        const cleanUrl = window.location.pathname + (cleanSearch ? "?" + cleanSearch : "") + window.location.hash;
        history.replaceState(null, "", cleanUrl);
    }
});

//Product Details — thumb slider sync (See More handled globally in auction.js)
$(document).ready(function () {
    $('.swiper-quickview-button-prev, .swiper-quickview-button-next').on('click', function() {
        setTimeout(function() {
            $('.quickviewSliderThumb2 .swiper-slide').removeClass('swiper-slide-thumb-active');
            $('.quickviewSliderThumb2 .swiper-slide-active').addClass('swiper-slide-thumb-active').trigger('click');
        }, 100);
    });
});