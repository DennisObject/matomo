<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo;

use App\Matomo\Api\ConfiguredBulkRequestLimit;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\ApiAuthentication;
use App\Matomo\Config\InstallationConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfiguredBulkRequestLimitTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $temporaryFile) {
            unlink($temporaryFile);
        }

        parent::tearDown();
    }

    #[DataProvider('limits')]
    public function test_resolves_the_limit_for_the_current_identity(
        int $configuredLimit,
        ?string $login,
        bool $hasViewAccess,
        int $expectedLimit,
    ): void {
        $authentication = new ApiAuthentication(null, false, false, null);
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('authenticatedLogin')->with($authentication)->willReturn($login);
        $authorizer->method('hasSomeViewAccess')->willReturn($hasViewAccess);

        $limit = new ConfiguredBulkRequestLimit(
            fn (): InstallationConfig => $this->configuration($configuredLimit),
            $authorizer,
        );

        $this->assertSame($expectedLimit, $limit->current($authentication));
    }

    /** @return iterable<string, array{int, ?string, bool, int}> */
    public static function limits(): iterable
    {
        yield 'anonymous without access' => [-1, null, false, 10];
        yield 'anonymous with access' => [-1, 'anonymous', true, 50];
        yield 'configured anonymous cap' => [20, 'anonymous', true, 20];
        yield 'authenticated configured cap' => [3, 'admin', true, 3];
        yield 'authenticated unlimited' => [-1, 'admin', true, -1];
    }

    private function configuration(int $limit): InstallationConfig
    {
        $path = tempnam(sys_get_temp_dir(), 'matomo-bulk-limit-');
        self::assertIsString($path);
        $this->temporaryFiles[] = $path;
        file_put_contents($path, <<<INI
            [database]
            dbname = "matomo"
            tables_prefix = "matomo_"

            [General]
            salt = "test-salt"
            API_bulk_request_limit = {$limit}
            INI);

        return InstallationConfig::fromFile($path);
    }
}
