<?php

namespace LatidoFlow\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use LatidoFlow\Laravel\Contracts\LatidoFlowClient;
use LatidoFlow\Laravel\LatidoFlowServiceProvider;
use LatidoFlow\Laravel\Runtime\HttpLatidoFlowClient;
use LatidoFlow\Laravel\Runtime\MonitorDefinitionPayload;
use LatidoFlow\Laravel\Tests\TestCase;

class PackageMetadataTest extends TestCase
{
    public function test_composer_metadata_declares_the_public_package_contract(): void
    {
        $composer = $this->composerMetadata();

        $this->assertSame('latidoflow/laravel', $composer['name']);
        $this->assertSame('library', $composer['type']);
        $this->assertSame('MIT', $composer['license']);
        $this->assertSame(
            'https://github.com/Elvesora/latidoflow-laravel/issues',
            $composer['support']['issues'],
        );
        $this->assertSame(
            'https://github.com/Elvesora/latidoflow-laravel',
            $composer['support']['source'],
        );
        $this->assertSame('^8.3', $composer['require']['php']);
        $this->assertSame('src/', $composer['autoload']['psr-4']['LatidoFlow\\Laravel\\']);
        $this->assertSame('tests/', $composer['autoload-dev']['psr-4']['LatidoFlow\\Laravel\\Tests\\']);
        $this->assertSame(
            [LatidoFlowServiceProvider::class],
            $composer['extra']['laravel']['providers'],
        );
        $this->assertArrayHasKey('illuminate/queue', $composer['require']);
        $this->assertArrayHasKey('orchestra/testbench', $composer['require-dev']);
        $this->assertArrayHasKey('phpunit/phpunit', $composer['require-dev']);
    }

    public function test_service_provider_registers_public_commands_bindings_and_configuration_publish_tag(): void
    {
        $commands = Artisan::all();

        $this->assertSame('https://www.latidoflow.com', config('latidoflow.endpoint'));
        $this->assertArrayHasKey('latidoflow:install', $commands);
        $this->assertArrayHasKey('latidoflow:sync', $commands);
        $this->assertArrayHasKey('latidoflow:verify', $commands);
        $this->assertInstanceOf(HttpLatidoFlowClient::class, $this->app->make(LatidoFlowClient::class));
        $this->assertSame(
            $this->app->make(MonitorDefinitionPayload::class),
            $this->app->make(MonitorDefinitionPayload::class),
        );

        $publishPaths = ServiceProvider::pathsToPublish(
            LatidoFlowServiceProvider::class,
            'latidoflow-config',
        );

        $this->assertCount(1, $publishPaths);
        $this->assertSame(config_path('latidoflow.php'), array_values($publishPaths)[0]);
        $this->assertStringEndsWith(
            DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'latidoflow.php',
            str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) array_keys($publishPaths)[0]),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function composerMetadata(): array
    {
        $contents = file_get_contents(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'composer.json');
        $this->assertIsString($contents);

        return json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    }
}
