<?php

namespace App\Services;

use App\Models\IconAsset;
use RuntimeException;

final class DdjThumbnailService
{
    public function render(IconAsset $asset): string
    {
        $root = realpath($asset->serverProfile->icon_root ?? '');
        if (! $root) {
            throw new RuntimeException('Icon root is unavailable.');
        }

        $source = realpath($root.DIRECTORY_SEPARATOR.str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $asset->relative_path));
        if (! $source || ! str_starts_with(strtolower($source), strtolower($root.DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('Icon path is outside the configured library.');
        }

        $cacheDirectory = storage_path('app/icon-cache');
        if (! is_dir($cacheDirectory) && ! mkdir($cacheDirectory, 0775, true) && ! is_dir($cacheDirectory)) {
            throw new RuntimeException('Unable to create icon cache.');
        }

        $target = $cacheDirectory.DIRECTORY_SEPARATOR.$asset->path_hash.'.png';
        if (! is_file($target) || filemtime($target) < filemtime($source)) {
            $this->decode($source, $target);
            $asset->forceFill(['thumbnail_status' => 'ready'])->save();
        }

        return $target;
    }

    private function decode(string $source, string $target): void
    {
        $data = file_get_contents($source);
        $dds = $data === false ? false : strpos($data, 'DDS ');
        if ($dds === false || ! str_starts_with($data, 'JMXVDDJ 1000')) {
            throw new RuntimeException('Invalid DDJ container.');
        }

        $height = $this->u32($data, $dds + 12);
        $width = $this->u32($data, $dds + 16);
        $fourCc = rtrim(substr($data, $dds + 84, 4), "\0 ");
        $rgbBits = $this->u32($data, $dds + 88);
        $payload = substr($data, $dds + 128);

        if ($width < 1 || $height < 1 || $width > 4096 || $height > 4096) {
            throw new RuntimeException('Unsupported DDJ dimensions.');
        }

        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        match ($fourCc) {
            'DXT1' => $this->decodeDxt($image, $payload, $width, $height, 1),
            'DXT3' => $this->decodeDxt($image, $payload, $width, $height, 3),
            'DXT5' => $this->decodeDxt($image, $payload, $width, $height, 5),
            default => $rgbBits === 32
                ? $this->decodeBgra($image, $payload, $width, $height)
                : throw new RuntimeException('Unsupported DDS format: '.($fourCc ?: 'RGB'.$rgbBits)),
        };

        if (! imagepng($image, $target, 6)) {
            imagedestroy($image);
            throw new RuntimeException('Unable to write thumbnail.');
        }
        imagedestroy($image);
    }

    private function decodeDxt(\GdImage $image, string $payload, int $width, int $height, int $variant): void
    {
        $blockBytes = $variant === 1 ? 8 : 16;
        $offset = 0;
        for ($by = 0; $by < (int) ceil($height / 4); $by++) {
            for ($bx = 0; $bx < (int) ceil($width / 4); $bx++) {
                $block = substr($payload, $offset, $blockBytes);
                if (strlen($block) < $blockBytes) {
                    throw new RuntimeException('Truncated DDS payload.');
                }
                $offset += $blockBytes;

                $alpha = array_fill(0, 16, 255);
                $colorOffset = 0;
                if ($variant === 3) {
                    for ($i = 0; $i < 8; $i++) {
                        $pair = ord($block[$i]);
                        $alpha[$i * 2] = ($pair & 0x0f) * 17;
                        $alpha[$i * 2 + 1] = (($pair >> 4) & 0x0f) * 17;
                    }
                    $colorOffset = 8;
                } elseif ($variant === 5) {
                    $alpha = $this->dxt5Alpha(substr($block, 0, 8));
                    $colorOffset = 8;
                }

                $c0 = unpack('v', substr($block, $colorOffset, 2))[1];
                $c1 = unpack('v', substr($block, $colorOffset + 2, 2))[1];
                $colors = $this->colorPalette($c0, $c1, $variant === 1);
                $indices = $this->u32($block, $colorOffset + 4);

                for ($pixel = 0; $pixel < 16; $pixel++) {
                    $x = $bx * 4 + ($pixel % 4);
                    $y = $by * 4 + intdiv($pixel, 4);
                    if ($x >= $width || $y >= $height) {
                        continue;
                    }
                    $index = ($indices >> ($pixel * 2)) & 0x03;
                    [$r, $g, $b, $paletteAlpha] = $colors[$index];
                    $a = min($alpha[$pixel], $paletteAlpha);
                    imagesetpixel($image, $x, $y, $this->gdColor($r, $g, $b, $a));
                }
            }
        }
    }

    private function dxt5Alpha(string $block): array
    {
        $a0 = ord($block[0]);
        $a1 = ord($block[1]);
        $palette = [$a0, $a1];
        if ($a0 > $a1) {
            for ($i = 1; $i <= 6; $i++) {
                $palette[] = (int) round((($a0 * (7 - $i)) + ($a1 * $i)) / 7);
            }
        } else {
            for ($i = 1; $i <= 4; $i++) {
                $palette[] = (int) round((($a0 * (5 - $i)) + ($a1 * $i)) / 5);
            }
            $palette[] = 0;
            $palette[] = 255;
        }

        $bits = 0;
        for ($i = 0; $i < 6; $i++) {
            $bits |= ord($block[$i + 2]) << ($i * 8);
        }

        $alpha = [];
        for ($i = 0; $i < 16; $i++) {
            $alpha[] = $palette[($bits >> ($i * 3)) & 0x07];
        }

        return $alpha;
    }

    private function colorPalette(int $c0, int $c1, bool $allowTransparent): array
    {
        $a = $this->rgb565($c0);
        $b = $this->rgb565($c1);
        if (! $allowTransparent || $c0 > $c1) {
            return [
                [...$a, 255], [...$b, 255],
                [$this->mix($a[0], $b[0], 2, 1, 3), $this->mix($a[1], $b[1], 2, 1, 3), $this->mix($a[2], $b[2], 2, 1, 3), 255],
                [$this->mix($a[0], $b[0], 1, 2, 3), $this->mix($a[1], $b[1], 1, 2, 3), $this->mix($a[2], $b[2], 1, 2, 3), 255],
            ];
        }

        return [
            [...$a, 255], [...$b, 255],
            [$this->mix($a[0], $b[0], 1, 1, 2), $this->mix($a[1], $b[1], 1, 1, 2), $this->mix($a[2], $b[2], 1, 1, 2), 255],
            [0, 0, 0, 0],
        ];
    }

    private function decodeBgra(\GdImage $image, string $payload, int $width, int $height): void
    {
        $required = $width * $height * 4;
        if (strlen($payload) < $required) {
            throw new RuntimeException('Truncated BGRA payload.');
        }
        $offset = 0;
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $b = ord($payload[$offset++]);
                $g = ord($payload[$offset++]);
                $r = ord($payload[$offset++]);
                $a = ord($payload[$offset++]);
                imagesetpixel($image, $x, $y, $this->gdColor($r, $g, $b, $a));
            }
        }
    }

    private function rgb565(int $color): array
    {
        return [
            (int) round((($color >> 11) & 31) * 255 / 31),
            (int) round((($color >> 5) & 63) * 255 / 63),
            (int) round(($color & 31) * 255 / 31),
        ];
    }

    private function mix(int $a, int $b, int $aw, int $bw, int $divisor): int
    {
        return (int) round(($a * $aw + $b * $bw) / $divisor);
    }

    private function gdColor(int $r, int $g, int $b, int $alpha): int
    {
        $gdAlpha = 127 - (int) round(max(0, min(255, $alpha)) * 127 / 255);

        return (($gdAlpha & 0x7f) << 24) | (($r & 0xff) << 16) | (($g & 0xff) << 8) | ($b & 0xff);
    }

    private function u32(string $data, int $offset): int
    {
        return (int) unpack('V', substr($data, $offset, 4))[1];
    }
}
