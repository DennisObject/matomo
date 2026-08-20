<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Api\Events\TourChallengesCollecting;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Plugins\PluginState;
use App\Matomo\Tour\TourDataRepository;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class TourApiTest extends TestCase
{
    public function test_superuser_can_read_localized_challenges_and_extension_results(): void
    {
        $this->authenticateSuperuser();
        $data = $this->createStub(TourDataRepository::class);
        $data->method('progress')->willReturn(['flatten_actions_skipped' => true]);
        $data->method('hasTrackedData')->willReturn(true);
        $this->app->instance(TourDataRepository::class, $data);
        Event::listen(TourChallengesCollecting::class, static function (TourChallengesCollecting $event): void {
            $event->challenges[] = [
                'id' => 'extension',
                'name' => 'Extension challenge',
                'description' => '',
                'isCompleted' => false,
                'isSkipped' => false,
                'url' => '',
            ];
        });

        $response = $this->get(
            '/index.php?module=API&method=Tour.getChallenges&format=json&token_auth=super-token',
        )->assertOk()->json();

        $this->assertIsArray($response);
        $this->assertSame('Embed a tracking code', $this->challenge($response, 'track_data')['name']);
        $this->assertTrue($this->challenge($response, 'track_data')['isCompleted']);
        $this->assertTrue($this->challenge($response, 'flatten_actions')['isSkipped']);
        $this->assertSame('Extension challenge', $this->challenge($response, 'extension')['name']);
        foreach (array_column($response, 'url') as $url) {
            $this->assertTrue($url === '' || str_starts_with((string) $url, 'index.php?'));
        }
    }

    public function test_superuser_can_read_current_level(): void
    {
        $this->authenticateSuperuser();
        $data = $this->createStub(TourDataRepository::class);
        $data->method('progress')->willReturn([
            'flatten_actions_completed' => true,
            'change_visualisations_skipped' => true,
        ]);
        $this->app->instance(TourDataRepository::class, $data);

        $this->get('/index.php?module=API&method=Tour.getLevel&format=json&token_auth=super-token')
            ->assertOk()
            ->assertJsonPath('currentLevel', 1)
            ->assertJsonPath('currentLevelName', 'Matomo beginner')
            ->assertJsonPath('challengesNeededForNextLevel', 3);
    }

    public function test_superuser_can_skip_an_incomplete_challenge(): void
    {
        $this->authenticateSuperuser();
        $data = $this->createMock(TourDataRepository::class);
        $data->method('progress')->willReturn([]);
        $data->method('hasTrackedData')->willReturn(false);
        $data->expects($this->once())->method('skip')->with('alice', 'flatten_actions');
        $this->app->instance(TourDataRepository::class, $data);

        $this->post(
            '/index.php?module=API&method=Tour.skipChallenge&id=flatten_actions'.
            '&format=json&token_auth=super-token',
        )->assertOk()->assertExactJson(['value' => true]);
    }

    public function test_completed_and_unknown_challenges_are_not_written(): void
    {
        $this->authenticateSuperuser();
        $data = $this->createMock(TourDataRepository::class);
        $data->method('progress')->willReturn(['flatten_actions_skipped' => true]);
        $data->method('hasTrackedData')->willReturn(true);
        $data->expects($this->never())->method('skip');
        $this->app->instance(TourDataRepository::class, $data);

        $this->post(
            '/index.php?module=API&method=Tour.skipChallenge&id=track_data'.
            '&format=json&token_auth=super-token',
        )->assertStatus(400)->assertJsonPath('message', 'Challenge already completed');
        $this->post(
            '/index.php?module=API&method=Tour.skipChallenge&id=unknown'.
            '&format=json&token_auth=super-token',
        )->assertStatus(400)->assertJsonPath('message', 'Challenge not found');
        $this->post(
            '/index.php?module=API&method=Tour.skipChallenge&id=flatten_actions'.
            '&format=json&token_auth=super-token',
        )->assertOk()->assertExactJson(['value' => true]);
    }

    public function test_tour_requires_a_superuser_and_a_challenge_id(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasSuperUserAccess')->willReturn(false);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);

        $this->get('/index.php?module=API&method=Tour.getChallenges&format=json')
            ->assertStatus(401)
            ->assertJsonPath(
                'message',
                "You can't access this resource as it requires a 'superuser' access.",
            );
        $this->post(
            '/index.php?module=API&method=Tour.skipChallenge&format=json&token_auth=super-token',
        )->assertStatus(400)->assertJsonPath('message', "Please specify a value for 'id'.");
    }

    private function authenticateSuperuser(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasSuperUserAccess')->willReturn(true);
        $authorizer->method('authenticatedLogin')->willReturn('alice');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(PluginState::class, new class implements PluginState
        {
            public function isActivated(string $pluginName): bool
            {
                return false;
            }
        });
    }

    /**
     * @param  array<array-key, mixed>  $challenges
     * @return array<string, mixed>
     */
    private function challenge(array $challenges, string $id): array
    {
        foreach ($challenges as $challenge) {
            if (is_array($challenge) && ($challenge['id'] ?? null) === $id) {
                return $challenge;
            }
        }

        $this->fail("Challenge {$id} was not returned.");
    }
}
