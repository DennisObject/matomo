<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Plugins\TrackerFileAvailability;
use Tests\TestCase;

class CustomJsTrackerApiTest extends TestCase
{
    public function test_returns_true_when_tracker_source_and_target_are_accessible(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasSomeAdminAccess')->willReturn(true);
        $files = $this->createMock(TrackerFileAvailability::class);
        $files->expects($this->once())->method('canUpdate')->willReturn(true);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(TrackerFileAvailability::class, $files);

        $this->get(
            '/index.php?module=API&method=CustomJsTracker.doesIncludePluginTrackersAutomatically'.
            '&format=json&token_auth=admin-token',
        )->assertOk()->assertExactJson(['value' => true]);
    }

    public function test_returns_false_when_tracker_files_cannot_be_updated(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasSomeAdminAccess')->willReturn(true);
        $files = $this->createStub(TrackerFileAvailability::class);
        $files->method('canUpdate')->willReturn(false);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(TrackerFileAvailability::class, $files);

        $this->get(
            '/index.php?module=API&method=CustomJsTracker.doesIncludePluginTrackersAutomatically'.
            '&format=json&token_auth=admin-token',
        )->assertOk()->assertExactJson(['value' => false]);
    }

    public function test_requires_admin_access_to_at_least_one_site(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasSomeAdminAccess')->willReturn(false);
        $files = $this->createMock(TrackerFileAvailability::class);
        $files->expects($this->never())->method('canUpdate');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(TrackerFileAvailability::class, $files);

        $this->get(
            '/index.php?module=API&method=CustomJsTracker.doesIncludePluginTrackersAutomatically'.
            '&format=json&token_auth=view-token',
        )->assertStatus(401)->assertExactJson([
            'result' => 'error',
            'message' => "You can't access this resource as it requires admin access for at least one website.",
        ]);
    }
}
