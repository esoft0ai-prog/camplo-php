<?php

/**
 * Works out the URL path the app is served under — '' on a site root (php artisan serve, Docker/Render), '/camplo' or
 * '/camplo/public' when Apache serves the project as a sub-folder of its web root (WAMP/XAMPP).
 *
 * Apache's SCRIPT_NAME is unreliable there (mod_fcgid, rewrites through the project-root .htaccess, and Windows paths
 * that differ in case from the URL), so the project's location under DOCUMENT_ROOT is tried first, then SCRIPT_NAME.
 * Matching ignores case, and the result keeps the casing of the requested URL.
 */
function camplo_base_path(array $server, string $publicDir): ?string
{
    $uri = (string) strtok((string) ($server['REQUEST_URI'] ?? '/'), '?');
    $norm = fn (string $p): string => rtrim(str_replace('\\', '/', realpath($p) ?: $p), '/');
    $candidates = [];
    // The project folder, as PHP sees it and as Apache addressed the script (they differ through symlinks/junctions).
    $projects = [$norm(dirname($publicDir))];
    $file = str_replace('\\', '/', (string) ($server['SCRIPT_FILENAME'] ?? ''));
    if (preg_match('#^(.*)/public/[^/]+\.php$#i', $file, $f)) {
        $projects[] = rtrim($f[1], '/');
    }
    foreach (array_filter([$server['CONTEXT_DOCUMENT_ROOT'] ?? '', $server['DOCUMENT_ROOT'] ?? '']) as $root) {
        foreach ([$norm($root), rtrim(str_replace('\\', '/', $root), '/')] as $r) {
            foreach ($projects as $project) {
                if ($r !== '' && stripos($project.'/', $r.'/') === 0) {
                    $candidates[] = ($server['CONTEXT_PREFIX'] ?? '').substr($project, strlen($r));
                }
            }
        }
    }
    $script = str_replace('\\', '/', (string) ($server['SCRIPT_NAME'] ?? ''));
    if (preg_match('#^(.*?)(?:/public)?/index\.php$#i', $script, $m)) {
        $candidates[] = $m[1];
    }
    foreach ($candidates as $dir) {
        $dir = rtrim($dir, '/');
        foreach ([$dir.'/public', $dir] as $base) {
            $len = strlen($base);
            if ($len === 0) {
                return '';
            }
            if (strncasecmp($uri, $base, $len) === 0 && (strlen($uri) === $len || $uri[$len] === '/')) {
                return substr($uri, 0, $len);
            }
        }
    }

    return null;
}
