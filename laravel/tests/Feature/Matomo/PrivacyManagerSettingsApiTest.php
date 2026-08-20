<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\PasswordConfirmationVerifier;
use App\Matomo\Geolocation\TrackerCacheInvalidator;
use App\Matomo\Options\MutableOptionRepository;
use App\Matomo\Privacy\DeletionBatchLimits;
use Tests\TestCase;

final class PrivacyManagerSettingsApiTest extends TestCase
{
    public function test_superuser_activates_do_not_track_and_clears_cache(): void
    {
        $this->authorize();
        $options = $this->createMock(MutableOptionRepository::class);
        $options->expects($this->once())->method('set')->with('PrivacyManager.doNotTrackEnabled', '1');
        $cache = $this->createMock(TrackerCacheInvalidator::class);
        $cache->expects($this->once())->method('clearGeneral');
        $this->app->instance(MutableOptionRepository::class, $options);
        $this->app->instance(TrackerCacheInvalidator::class, $cache);

        $this->get('/index.php?module=API&method=PrivacyManager.activateDoNotTrack'.
            '&format=json&token_auth=super-token')->assertOk()->assertExactJson(['value' => true]);
    }

    public function test_delete_log_settings_are_normalized_and_confirmed(): void
    {
        $this->authorize();
        $passwords = $this->createStub(PasswordConfirmationVerifier::class);
        $passwords->method('isCorrect')->willReturn(true);
        $values = [];
        $options = $this->createStub(MutableOptionRepository::class);
        $options->method('set')->willReturnCallback(
            static function (string $name, string $value) use (&$values): void {
                $values[$name] = $value;
            },
        );
        $this->app->instance(PasswordConfirmationVerifier::class, $passwords);
        $this->app->instance(MutableOptionRepository::class, $options);
        $this->app->instance(TrackerCacheInvalidator::class, $this->createStub(TrackerCacheInvalidator::class));

        $this->get('/index.php?module=API&method=PrivacyManager.setDeleteLogsSettings'.
            '&enableDeleteLogs=1&deleteLogsOlderThan=0&passwordConfirmation=correct'.
            '&format=json&token_auth=super-token')->assertOk();

        $this->assertSame(['delete_logs_enable' => '1', 'delete_logs_older_than' => '1'], $values);
    }

    public function test_non_superuser_cannot_change_privacy_settings(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasSuperUserAccess')->willReturn(false);
        $options = $this->createMock(MutableOptionRepository::class);
        $options->expects($this->never())->method('set');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(MutableOptionRepository::class, $options);

        $this->get('/index.php?module=API&method=PrivacyManager.deactivateDoNotTrack'.
            '&format=json&token_auth=user-token')->assertUnauthorized();
    }

    public function test_delete_report_settings_preserve_deletion_batch_limits(): void
    {
        $this->authorize();
        $passwords = $this->createStub(PasswordConfirmationVerifier::class);
        $passwords->method('isCorrect')->willReturn(true);
        $values = [];
        $options = $this->createStub(MutableOptionRepository::class);
        $options->method('set')->willReturnCallback(
            static function (string $name, string $value) use (&$values): void {
                $values[$name] = $value;
            },
        );
        $this->app->instance(PasswordConfirmationVerifier::class, $passwords);
        $this->app->instance(MutableOptionRepository::class, $options);
        $this->app->instance(TrackerCacheInvalidator::class, $this->createStub(TrackerCacheInvalidator::class));

        $limits = $this->createStub(DeletionBatchLimits::class);
        $limits->method('logs')->willReturn(100_000);
        $limits->method('unusedActions')->willReturn(100_000);
        $this->app->instance(DeletionBatchLimits::class, $limits);

        $this->get('/index.php?module=API&method=PrivacyManager.setDeleteReportsSettings'.
            '&passwordConfirmation=correct&format=json&token_auth=super-token')->assertOk();

        $this->assertSame('100000', $values['delete_logs_max_rows_per_query']);
        $this->assertSame('100000', $values['delete_logs_unused_actions_max_rows_per_query']);
    }

    private function authorize(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasSuperUserAccess')->willReturn(true);
        $authorizer->method('authenticatedLogin')->willReturn('admin');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
    }
}
