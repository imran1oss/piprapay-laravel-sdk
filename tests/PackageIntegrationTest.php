<?php

namespace Imran1oss\PipraPay\Tests;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Console\VendorPublishCommand;
use Illuminate\Foundation\PackageManifest;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\ServiceProvider;
use Imran1oss\PipraPay\Contracts\PipraPayClientInterface;
use Imran1oss\PipraPay\Facades\PipraPay;
use Imran1oss\PipraPay\PipraPayClient;
use Imran1oss\PipraPay\PipraPayServiceProvider;
use Imran1oss\PipraPay\Exceptions\PipraPayConfigurationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class PackageIntegrationTest extends TestCase
{
    private Application $app;
    private string $directory;
    private $previousContainer;
    private $previousFacadeApplication;
    private array $previousPublishes;
    private array $previousPublishGroups;
    private string $previousTimezone;
    private string $previousEncoding;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousTimezone = date_default_timezone_get();
        $this->previousEncoding = mb_internal_encoding();
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $this->previousPublishes = ServiceProvider::$publishes;
        $this->previousPublishGroups = ServiceProvider::$publishGroups;
        $this->directory = sys_get_temp_dir().'/piprapay-sdk-'.bin2hex(random_bytes(8));
        (new Filesystem())->ensureDirectoryExists($this->directory.'/config');
        $this->app = new class($this->directory) extends Application
        {
            public function getCachedConfigPath()
            {
                return $this->basePath('cached-config.php');
            }
        };
        $this->app->useConfigPath($this->directory.'/config');
        $this->app->instance('config', new Repository([
            'app' => ['env' => 'testing', 'timezone' => 'UTC'],
            'piprapay' => ['base_url' => 'https://pay.example.com', 'api_key' => 'test-key'],
        ]));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Container::setInstance($this->previousContainer);
        ServiceProvider::$publishes = $this->previousPublishes;
        ServiceProvider::$publishGroups = $this->previousPublishGroups;
        (new Filesystem())->deleteDirectory($this->directory);
        date_default_timezone_set($this->previousTimezone);
        mb_internal_encoding($this->previousEncoding);
        parent::tearDown();
    }

    public function test_composer_package_discovery_exposes_the_provider_and_facade(): void
    {
        $files = new Filesystem();
        $files->ensureDirectoryExists($this->directory.'/vendor/composer');
        $composer = json_decode(file_get_contents(dirname(__DIR__).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $files->put($this->directory.'/composer.json', '{}');
        $files->put($this->directory.'/vendor/composer/installed.json', json_encode(['packages' => [
            ['name' => $composer['name'], 'extra' => $composer['extra']],
        ]], JSON_THROW_ON_ERROR));
        $manifest = new PackageManifest($files, $this->directory, $this->directory.'/packages.php');
        $manifest->vendorPath = $this->directory.'/vendor';
        $manifest->build();
        $this->assertContains(PipraPayServiceProvider::class, $manifest->providers());
        $this->assertSame(PipraPay::class, $manifest->aliases()['PipraPay']);
    }

    public function test_contract_alias_and_facade_use_the_registered_client(): void
    {
        $http = $this->fakeVerification('https://pay.example.com/api/verify-payment');
        $provider = new PipraPayServiceProvider($this->app);
        $provider->register();
        $this->assertSame($this->app->make(PipraPayClientInterface::class), $this->app->make(PipraPayClient::class));
        $this->assertTrue(PipraPay::verifyPayment('349452200706799329851862826')->isCompleted());
        $http->assertSentCount(1);
    }

    public function test_configuration_can_be_published_using_the_documented_tag(): void
    {
        $provider = new PipraPayServiceProvider($this->app);
        $provider->register();
        $provider->boot();
        $command = new VendorPublishCommand(new Filesystem());
        $command->setLaravel($this->app);
        $exitCode = $command->run(new ArrayInput([
            '--provider' => PipraPayServiceProvider::class, '--tag' => ['piprapay-config'], '--force' => true,
        ]), new BufferedOutput());
        $this->assertSame(0, $exitCode);
        $this->assertFileExists($this->directory.'/config/piprapay.php');
        $this->assertSame(file_get_contents(dirname(__DIR__).'/config/piprapay.php'), file_get_contents($this->directory.'/config/piprapay.php'));
    }

    public function test_cached_configuration_controls_the_registered_client(): void
    {
        $config = $this->app['config']->all();
        $config['piprapay'] = ['base_url' => 'https://cached.example.com/panel', 'api_key' => 'cached-test-key',
            'currency' => 'USDT', 'endpoints' => ['verify' => '/api/verify-payment', 'checkout' => '/api/checkout/redirect']];
        file_put_contents($this->app->getCachedConfigPath(), '<?php return '.var_export($config, true).';');
        (new LoadConfiguration())->bootstrap($this->app);
        $this->assertTrue($this->app->configurationIsCached());
        $http = $this->fakeVerification('https://cached.example.com/panel/api/verify-payment');
        (new PipraPayServiceProvider($this->app))->register();
        $this->assertTrue($this->app->make(PipraPayClientInterface::class)->verifyPayment('349452200706799329851862826')->isCompleted());
        $http->assertSent(fn ($request): bool => $request->hasHeader('MHS-PIPRAPAY-API-KEY', 'cached-test-key'));
    }

    #[DataProvider('invalidConfigurationValues')]
    public function test_malformed_configuration_is_not_string_cast_into_valid_values(string $field, mixed $value): void
    {
        $this->app['config']->set('piprapay.'.$field, $value);
        (new PipraPayServiceProvider($this->app))->register();
        set_error_handler(static function (int $severity, string $message): never {
            throw new \ErrorException($message, 0, $severity);
        });
        $this->expectException(PipraPayConfigurationException::class);
        try {
            $this->app->make(PipraPayClientInterface::class);
        } finally {
            restore_error_handler();
        }
    }

    public static function invalidConfigurationValues(): array
    {
        return [['currency', []], ['currency', true], ['currency', 123], ['base_url', []], ['api_key', []]];
    }

    private function fakeVerification(string $url): Factory
    {
        $http = new Factory();
        $http->preventStrayRequests();
        $http->fake([$url => Factory::response(file_get_contents(__DIR__.'/Fixtures/payment-completed.json'))]);
        $this->app->instance(Factory::class, $http);

        return $http;
    }
}
