<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\CoreAdmin\Events\CustomLogoChanged;
use App\Matomo\CoreAdmin\FileBrandingManager;
use App\Matomo\Options\MutableOptionRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class FileBrandingManagerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/matomo-branding-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_publishes_and_then_removes_the_current_users_branding_files(): void
    {
        $files = new Filesystem;
        $temporaryRoot = $this->directory.'/tmp/logos';
        $temporary = $temporaryRoot.'/'.sha1('alice');
        $public = $this->directory.'/public/instance-one';
        $files->ensureDirectoryExists($temporary);
        file_put_contents($temporary.'/logo.png', 'large');
        file_put_contents($temporary.'/logo-header.png', 'small');
        file_put_contents($temporary.'/favicon.png', 'icon');
        $options = new class implements MutableOptionRepository
        {
            /** @var list<array{name: string, value: string, autoload: bool}> */
            public array $writes = [];

            public function value(string $name): ?string
            {
                return null;
            }

            public function set(string $name, string $value, bool $autoload = false): void
            {
                $this->writes[] = compact('name', 'value', 'autoload');
            }
        };
        $changed = [];
        Event::listen(CustomLogoChanged::class, static function (CustomLogoChanged $event) use (&$changed): void {
            $changed[] = $event->absolutePath;
        });
        $manager = new FileBrandingManager(
            options: $options,
            files: $files,
            events: $this->app->make(Dispatcher::class),
            publicDirectory: $public,
            publicRelativeDirectory: 'misc/user/instance-one',
            temporaryLogosDirectory: $temporaryRoot,
        );

        $this->assertSame([
            'useCustomLogo' => true,
            'customLogoPath' => 'misc/user/instance-one/logo.png',
            'customFaviconPath' => 'misc/user/instance-one/favicon.png',
        ], $manager->update('alice', true, true, true));
        $this->assertSame('large', file_get_contents($public.'/logo.png'));
        $this->assertSame('small', file_get_contents($public.'/logo-header.png'));
        $this->assertSame('icon', file_get_contents($public.'/favicon.png'));
        $this->assertSame([
            $public.'/logo.png',
            $public.'/logo-header.png',
            $public.'/favicon.png',
        ], $changed);
        $this->assertFalse($files->exists($temporary.'/logo.png'));

        $files->ensureDirectoryExists($temporary);
        file_put_contents($temporary.'/unused.png', 'unused');
        $this->assertSame(
            ['useCustomLogo' => false],
            $manager->update('alice', false, true, true),
        );
        $this->assertFalse($files->exists($public.'/logo.png'));
        $this->assertFalse($files->isDirectory($temporary));
        $this->assertSame([
            ['name' => 'branding_use_custom_logo', 'value' => '1', 'autoload' => true],
            ['name' => 'branding_use_custom_logo', 'value' => '0', 'autoload' => true],
        ], $options->writes);
    }

    public function test_enables_branding_without_claiming_paths_when_uploads_are_missing(): void
    {
        $options = $this->createMock(MutableOptionRepository::class);
        $options->expects($this->once())->method('set')->with('branding_use_custom_logo', '1', true);
        $manager = new FileBrandingManager(
            options: $options,
            files: new Filesystem,
            events: $this->app->make(Dispatcher::class),
            publicDirectory: $this->directory.'/public',
            publicRelativeDirectory: 'misc/user',
            temporaryLogosDirectory: $this->directory.'/tmp/logos',
        );

        $this->assertSame(
            ['useCustomLogo' => true],
            $manager->update('alice', true, true, true),
        );
    }
}
