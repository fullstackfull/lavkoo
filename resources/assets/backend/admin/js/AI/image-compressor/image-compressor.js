$(document).on('change', '.image-compressor', function (e) {
    const file = e.target.files[0];
    if (!file) return;


    const originalInput = document.getElementById('aiImageUploadOriginal');
    if (originalInput) {
        const dataTransferOriginal = new DataTransfer();
        dataTransferOriginal.items.add(file);
        originalInput.files = dataTransferOriginal.files;
    }

    new Compressor(file, {
        quality: 0.10,
        maxWidth: 800,
        maxHeight: 800,
        mimeType: 'image/jpeg',
        convertSize: Infinity,
        crop: false,
        success(result) {
            const compressedFile = new File([result], file.name.replace(/\.\w+$/, '.jpg'), {
                type: 'image/jpeg',
                lastModified: Date.now()
            });

            const dataTransfer = new DataTransfer();
            dataTransfer.items.add(compressedFile);
            e.target.files = dataTransfer.files;
        },
        error(err) {
            console.warn('Compression error:', err.message);
        }
    });
});
