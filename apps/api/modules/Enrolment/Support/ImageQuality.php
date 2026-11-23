<?php

declare(strict_types=1);

namespace Modules\Enrolment\Support;

/**
 * Is a document photo good enough to read? Measures size, sharpness (variance of the
 * Laplacian on a grey, downscaled copy), glare (blown-out pixels) and darkness.
 * The phone runs a quick check first; this is the record kept with the document.
 */
final class ImageQuality
{
    public const MIN_SHORT_SIDE = 800;

    public const MIN_SHARPNESS = 150.0; // readable text scores in the thousands; a shaky photo well under 150

    public const MAX_GLARE = 0.08;

    public const MIN_BRIGHTNESS = 45.0;

    /** @return array{ok: bool, width: int, height: int, sharpness: float, glare: float, brightness: float, problems: list<string>} */
    public static function check(string $bytes): array
    {
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return ['ok' => false, 'width' => 0, 'height' => 0, 'sharpness' => 0.0, 'glare' => 0.0, 'brightness' => 0.0, 'problems' => ['We could not open this picture.']];
        }
        $w = imagesx($image);
        $h = imagesy($image);
        // Work on a copy about 600 px wide: fast, and blur shows just as well.
        $scale = min(1.0, 600 / max(1, $w));
        $sw = max(3, (int) round($w * $scale));
        $sh = max(3, (int) round($h * $scale));
        $small = imagecreatetruecolor($sw, $sh);
        imagecopyresampled($small, $image, 0, 0, 0, 0, $sw, $sh, $w, $h);
        imagedestroy($image);

        $grey = [];
        $sum = 0.0;
        $blown = 0;
        for ($y = 0; $y < $sh; $y++) {
            for ($x = 0; $x < $sw; $x++) {
                $rgb = imagecolorat($small, $x, $y);
                $g = 0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF);
                $grey[$y][$x] = $g;
                $sum += $g;
                if ($g >= 250) {
                    $blown++;
                }
            }
        }
        imagedestroy($small);

        $n = 0;
        $mean = 0.0;
        $m2 = 0.0;
        for ($y = 1; $y < $sh - 1; $y++) {
            for ($x = 1; $x < $sw - 1; $x++) {
                $lap = $grey[$y - 1][$x] + $grey[$y + 1][$x] + $grey[$y][$x - 1] + $grey[$y][$x + 1] - 4 * $grey[$y][$x];
                $n++;
                $delta = $lap - $mean;
                $mean += $delta / $n;
                $m2 += $delta * ($lap - $mean);
            }
        }
        $sharpness = $n > 1 ? $m2 / ($n - 1) : 0.0;
        $pixels = $sw * $sh;
        $glare = $blown / $pixels;
        $brightness = $sum / $pixels;

        $problems = [];
        if (min($w, $h) < self::MIN_SHORT_SIDE) {
            $problems[] = 'The picture is too small to read. Take it closer, or use the camera instead of a screenshot.';
        }
        if ($sharpness < self::MIN_SHARPNESS) {
            $problems[] = 'The picture is blurry. Hold the phone still, and tap the screen to focus.';
        }
        if ($glare > self::MAX_GLARE) {
            $problems[] = 'There is glare on the document. Move away from the light or turn off the flash.';
        }
        if ($brightness < self::MIN_BRIGHTNESS) {
            $problems[] = 'The picture is too dark. Find more light.';
        }

        return ['ok' => $problems === [], 'width' => $w, 'height' => $h, 'sharpness' => round($sharpness, 1),
            'glare' => round($glare, 3), 'brightness' => round($brightness, 1), 'problems' => $problems];
    }
}
