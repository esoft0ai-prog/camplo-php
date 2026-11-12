<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage as Disk;
use Throwable;

/**
 * Object storage: R2/S3 when STORAGE_ENDPOINT + STORAGE_BUCKET are set; otherwise files live in the
 * `stored_files` table, so the product works with just a database (same as the Node version).
 */
final class Storage
{
    private static function s3(): ?\Illuminate\Contracts\Filesystem\Filesystem
    {
        $c = config('camplo.s3');
        if (! $c['endpoint'] || ! $c['bucket']) {
            return null;
        }

        return Disk::build(['driver' => 's3', 'key' => $c['key'], 'secret' => $c['secret'], 'region' => $c['region'], 'bucket' => $c['bucket'], 'endpoint' => $c['endpoint'], 'use_path_style_endpoint' => true, 'throw' => true]);
    }

    public static function put(string $path, string $data, string $contentType): void
    {
        if ($s3 = self::s3()) {
            $s3->put($path, $data, ['ContentType' => $contentType]);

            return;
        }
        DB::table('stored_files')->upsert([['path' => $path, 'content_type' => $contentType, 'data_base64' => base64_encode($data)]], ['path'], ['content_type', 'data_base64']);
    }

    /** @return array{data:string,contentType:string}|null */
    public static function get(string $path): ?array
    {
        if ($s3 = self::s3()) {
            try {
                return ['data' => $s3->get($path), 'contentType' => $s3->mimeType($path) ?: self::contentTypeFor($path)];
            } catch (Throwable) {
                return null;
            }
        }
        $row = DB::table('stored_files')->where('path', $path)->first();

        return $row ? ['data' => base64_decode($row->data_base64), 'contentType' => $row->content_type] : null;
    }

    public static function removePrefix(string $prefix): void
    {
        if ($s3 = self::s3()) {
            foreach ($s3->allFiles($prefix) as $f) {
                $s3->delete($f);
            }

            return;
        }
        DB::table('stored_files')->where('path', 'like', addcslashes($prefix, '%_\\').'%')->delete();
    }

    private const TYPES = [
        'html' => 'text/html; charset=utf-8', 'htm' => 'text/html; charset=utf-8', 'css' => 'text/css; charset=utf-8', 'js' => 'text/javascript; charset=utf-8',
        'mjs' => 'text/javascript; charset=utf-8', 'json' => 'application/json', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif', 'ico' => 'image/x-icon', 'woff' => 'font/woff',
        'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'otf' => 'font/otf', 'txt' => 'text/plain; charset=utf-8', 'xml' => 'application/xml',
        'webmanifest' => 'application/manifest+json', 'mp4' => 'video/mp4', 'webm' => 'video/webm', 'pdf' => 'application/pdf',
    ];

    public static function contentTypeFor(string $file): string
    {
        return self::TYPES[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
    }
}
