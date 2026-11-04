<?php

namespace App\Traits;

/**
 * An image a merchant may add, replace, or take away again.
 *
 * The required images in this application are handled by every form the same way and need nothing
 * shared. An OPTIONAL one has a third state the upload field cannot express: an empty file input
 * means "keep what is stored", so without an explicit removal the merchant can never get back to
 * having none — which is how a mobile image, once uploaded, became permanent.
 *
 * The three places that offer one — banners, categories, brands — behave identically here, so the
 * rule lives once: a new file replaces (and deletes what it replaced), the removal checkbox
 * deletes, and anything else leaves the stored file exactly as it was.
 */
trait ManagesOptionalImage
{
    use FileManagerTrait;

    protected function getProcessedMobileImage(
        object $request,
        string $directory,
        ?string $storedImage,
        string $fileKey = 'mobile_image',
        string $removeKey = 'remove_mobile_image',
    ): ?string {
        $uploaded = $request->file($fileKey);

        if ($uploaded) {
            return $storedImage
                ? $this->update(dir: $directory, oldImage: $storedImage, format: 'webp', image: $uploaded)
                : $this->upload(dir: $directory, format: 'webp', image: $uploaded);
        }

        if ($storedImage && $request[$removeKey]) {
            $this->delete(filePath: '/' . trim($directory, '/') . '/' . $storedImage);

            return null;
        }

        return $storedImage;
    }
}
