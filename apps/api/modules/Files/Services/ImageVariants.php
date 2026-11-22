<?php

declare(strict_types=1);

namespace Modules\Files\Services;

use GdImage;
use Modules\Files\Exceptions\FileError;

/**
 * Makes resized WebP copies of an image with GD. Re-encoding also drops metadata
 * (EXIF, GPS) and anything hidden after the image data, so only variants are ever
 * served publicly, never the original upload.
 */
final class ImageVariants
{
    public function __construct(private readonly int $maxPixels) {}

    /**
     * @param  array<string, int>  $sizes  name => longest side in pixels
     * @return array<string, array{bytes: string, width: int, height: int}>
     */
    public function make(string $bytes, array $sizes): array
    {
        $info = @getimagesizefromstring($bytes);

        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            throw new FileError('This is not an image we can read.', 'not_an_image');
        }

        if ($this->maxPixels < $info[0] * $info[1]) {
            throw new FileError('The image is too large. Use one under 16 megapixels.', 'image_too_large');
        }

        $source = @imagecreatefromstring($bytes);

        if (! $source instanceof GdImage) {
            throw new FileError('This is not an image we can read.', 'not_an_image');
        }

        $out = [];

        try {
            foreach ($sizes as $name => $longest) {
                $out[$name] = $this->resize($source, $info[0], $info[1], $longest);
            }
        } finally {
            imagedestroy($source);
        }

        return $out;
    }

    /** @return array{bytes: string, width: int, height: int} */
    private function resize(GdImage $source, int $width, int $height, int $longest): array
    {
        $scale = min(1, $longest / max($width, $height));
        $w = max(1, (int) round($width * $scale));
        $h = max(1, (int) round($height * $scale));

        $target = imagecreatetruecolor($w, $h);
        if (! $target instanceof GdImage) {
            throw new FileError('Could not process the image.', 'image_failed');
        }

        try {
            imagealphablending($target, false);
            imagesavealpha($target, true);
            imagefill($target, 0, 0, (int) imagecolorallocatealpha($target, 0, 0, 0, 127));
            imagecopyresampled($target, $source, 0, 0, 0, 0, $w, $h, $width, $height);

            ob_start();
            imagewebp($target, null, 85);
            $bytes = (string) ob_get_clean();
        } finally {
            imagedestroy($target);
        }

        return ['bytes' => $bytes, 'width' => $w, 'height' => $h];
    }
}
