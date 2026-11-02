<?php

declare(strict_types=1);

namespace Streeboga\EntityGenerator\Tests;

use Orchestra\Testbench\TestCase as Base;
use Streeboga\EntityGenerator\EntityGeneratorServiceProvider;

abstract class TestCase extends Base
{
    protected string $sandbox;

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir().'/entity-gen-'.bin2hex(random_bytes(4));
        mkdir($this->sandbox.'/routes', 0777, true);
        file_put_contents($this->sandbox.'/routes/api.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n");
        parent::setUp();
        $this->app->setBasePath($this->sandbox);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        exec('rm -rf '.escapeshellarg($this->sandbox));
    }

    protected function getPackageProviders($app): array
    {
        return [EntityGeneratorServiceProvider::class];
    }

    /** @return list<string> */
    protected function phpFiles(): array
    {
        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->sandbox, \FilesystemIterator::SKIP_DOTS)) as $f) {
            str_ends_with((string) $f, '.php') && $out[] = (string) $f;
        }

        return $out;
    }
}
