<?php

namespace App\Services;

use App\Models\IconAsset;
use App\Models\ServerProfile;
use Carbon\Carbon;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class IconLibraryService
{
    public function scan(ServerProfile $profile): array
    {
        $root = $profile->icon_root ? realpath($profile->icon_root) : false;
        if (! $root || ! is_dir($root)) {
            throw new RuntimeException('Choose a valid extracted DDJ icon folder first.');
        }

        set_time_limit(0);
        $rows = [];
        $scanned = 0;
        $invalid = 0;
        $now = now();
        $scanStartedAt = $now->copy();
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (! $file->isFile() || strtolower($file->getExtension()) !== 'ddj') {
                continue;
            }

            $relative = ltrim(substr($file->getPathname(), strlen($root)), '\\/');
            $relative = str_replace('/', '\\', $relative);
            $meta = $this->metadata($file->getPathname());
            if (! $meta['valid']) {
                $invalid++;
            }

            $rows[] = [
                'server_profile_id' => $profile->id,
                'relative_path' => $relative,
                'path_hash' => hash('sha256', strtolower($relative)),
                'filename' => $file->getFilename(),
                'file_size' => $file->getSize(),
                'width' => $meta['width'],
                'height' => $meta['height'],
                'format' => $meta['format'],
                'thumbnail_status' => $meta['valid'] ? 'pending' : 'invalid',
                'source_modified_at' => Carbon::createFromTimestamp($file->getMTime()),
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $scanned++;

            if (count($rows) >= 500) {
                $this->upsert($rows);
                $rows = [];
            }
        }

        if ($rows) {
            $this->upsert($rows);
        }

        // Reconciliation only runs after the iterator completed successfully;
        // a partial scan can therefore never hide valid assets.
        $stale = IconAsset::query()
            ->where('server_profile_id', $profile->id)
            ->where(function ($query) use ($scanStartedAt): void {
                $query->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $scanStartedAt);
            })
            ->count();
        IconAsset::query()
            ->where('server_profile_id', $profile->id)
            ->where(function ($query) use ($scanStartedAt): void {
                $query->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $scanStartedAt);
            })
            ->delete();

        return ['scanned' => $scanned, 'invalid' => $invalid, 'removed' => $stale, 'root' => $root];
    }

    private function upsert(array $rows): void
    {
        IconAsset::upsert(
            $rows,
            ['server_profile_id', 'path_hash'],
            ['relative_path', 'filename', 'file_size', 'width', 'height', 'format', 'thumbnail_status', 'source_modified_at', 'last_seen_at', 'updated_at']
        );
    }

    private function metadata(string $path): array
    {
        $header = file_get_contents($path, false, null, 0, 180);
        $dds = $header === false ? false : strpos($header, 'DDS ');
        if ($dds === false || ! str_starts_with($header, 'JMXVDDJ 1000')) {
            return ['valid' => false, 'width' => null, 'height' => null, 'format' => null];
        }

        $height = $this->u32($header, $dds + 12);
        $width = $this->u32($header, $dds + 16);
        $fourCc = rtrim(substr($header, $dds + 84, 4), "\0 ");
        $rgbBits = $this->u32($header, $dds + 88);

        return [
            'valid' => $width > 0 && $height > 0,
            'width' => $width ?: null,
            'height' => $height ?: null,
            'format' => $fourCc ?: ($rgbBits ? 'RGB'.$rgbBits : 'DDS'),
        ];
    }

    private function u32(string $data, int $offset): int
    {
        if (strlen($data) < $offset + 4) {
            return 0;
        }

        return (int) unpack('V', substr($data, $offset, 4))[1];
    }
}
