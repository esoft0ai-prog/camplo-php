<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Base-path detection for sub-folder installs (bootstrap/basepath.php), including Windows/WAMP + mod_fcgid values. */
class BasePathTest extends TestCase
{
    private string $public;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2).'/bootstrap/basepath.php';
        // A fake project folder "Camplo" under a fake web root, like C:\wamp64\www\Camplo
        $this->public = sys_get_temp_dir().'/camplo-bp/www/Camplo/public';
        @mkdir($this->public, 0777, true);
    }

    private function base(string $uri, string $script, ?string $docRoot = null): ?string
    {
        return camplo_base_path(['REQUEST_URI' => $uri, 'SCRIPT_NAME' => $script, 'DOCUMENT_ROOT' => $docRoot ?? dirname($this->public, 2)], $this->public);
    }

    public function test_wamp_sub_folder_with_different_case_and_public(): void
    {
        // The case from the bug report: folder Camplo, URL /camplo/public/..., SCRIPT_NAME not usable.
        $this->assertSame('/camplo/public', $this->base('/camplo/public/api/auth/login', '/Camplo/public/index.php'));
        $this->assertSame('/camplo/public', $this->base('/camplo/public/api/auth/login', '/fcgi-bin/php-cgi.exe'));
        $this->assertSame('/camplo', $this->base('/camplo/api/auth/login', '/Camplo/public/index.php'));
        $this->assertSame('/camplo', $this->base('/camplo/', '/camplo/public/index.php'));
        $this->assertSame('/CAMPLO', $this->base('/CAMPLO/api/health?x=1', ''));
        // Windows-style document root
        $this->assertSame('/camplo', $this->base('/camplo/api/health', '', str_replace('/', '\\', dirname($this->public, 2))));
    }

    public function test_windows_script_filename_with_other_case(): void
    {
        // php-cgi under mod_fcgid on Windows: SCRIPT_NAME is the CGI binary; the folder comes from SCRIPT_FILENAME.
        $server = ['REQUEST_URI' => '/camplo/public/api/auth/login', 'SCRIPT_NAME' => '/cgi-bin/php-cgi.exe',
            'SCRIPT_FILENAME' => 'C:\\wamp64\\www\\Camplo\\public\\index.php', 'DOCUMENT_ROOT' => 'C:/wamp64/www'];
        $this->assertSame('/camplo/public', camplo_base_path($server, '/nonexistent/elsewhere/public'));
        $this->assertSame('/camplo', camplo_base_path([...$server, 'REQUEST_URI' => '/camplo/api/auth/login'], '/nonexistent/elsewhere/public'));
    }

    public function test_site_root_installs(): void
    {
        // Docker/Render and php artisan serve: document root is public/ itself
        $this->assertSame('', $this->base('/api/health', '/index.php', $this->public));
        $this->assertSame('', $this->base('/', '/index.php', $this->public));
        // A virtual host pointing at the project folder
        $this->assertSame('', $this->base('/api/health', '/public/index.php', dirname($this->public)));
        $this->assertSame('/public', $this->base('/public/api/health', '/public/index.php', dirname($this->public)));
    }
}
