<?php

declare(strict_types=1);

namespace Tests\Unit;

use Jengo\Api\Installers\ApiInstaller;
use Tests\TestCase;

final class ApiInstallerTest extends TestCase
{
    private string $testConfig;
    private string $testRoutes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testConfig = APPPATH . 'Config/JengoApi.php';
        $this->testRoutes = APPPATH . 'Config/Routes.php';

        if (!is_dir(APPPATH . 'Config')) {
            mkdir(APPPATH . 'Config', 0777, true);
        }
    }

    protected function tearDown(): void
    {
        if (file_exists($this->testConfig)) {
            unlink($this->testConfig);
        }

        parent::tearDown();
    }

    public function testMetadata(): void
    {
        $this->assertSame('api', ApiInstaller::name());
        $this->assertNotEmpty(ApiInstaller::description());
        $this->assertNotEmpty(ApiInstaller::reasonForSkipping());
        $this->assertSame([], ApiInstaller::dependencies());
    }

    public function testShouldRunWhenConfigMissing(): void
    {
        if (file_exists($this->testConfig)) {
            unlink($this->testConfig);
        }

        $installer = new ApiInstaller();
        $this->assertTrue($installer->shouldRun());
    }

    public function testInstallPublishesConfigAndAppendsRoute(): void
    {
        if (file_exists($this->testConfig)) {
            unlink($this->testConfig);
        }

        if (!file_exists($this->testRoutes)) {
            file_put_contents($this->testRoutes, "<?php\nuse CodeIgniter\\Router\\RouteCollection;\n");
        }

        $installer = new ApiInstaller();
        $installer->install();

        $this->assertFileExists($this->testConfig);
        $configContent = file_get_contents($this->testConfig);
        $this->assertStringContainsString('class JengoApi extends BaseJengoApi', $configContent);

        $routesContent = file_get_contents($this->testRoutes);
        $this->assertStringContainsString('Router::publish', $routesContent);
        $this->assertSame(1, $installer->runs);
    }
}
