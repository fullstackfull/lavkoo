<div class="modal fade imgViewModal" id="video-view-modal" tabindex="-1"
    aria-labelledby="videoViewModalLabel" role="dialog" aria-modal="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content bg-transparent border-0">
            <div class="modal-body p-0">
                <div class="d-flex justify-content-center align-items-center p-2">
                    <div class="embed-responsive-wrapper">
                        <iframe
                            src="{{ $product['video_url'] ?? '' }}"
                            title="YouTube video player"
                            frameborder="0"
                            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                            referrerpolicy="strict-origin-when-cross-origin"
                            allowfullscreen
                        ></iframe>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
