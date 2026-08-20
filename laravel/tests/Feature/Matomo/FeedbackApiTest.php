<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Feedback\FeedbackFeatureNameResolver;
use App\Matomo\Feedback\FeedbackMailer;
use App\Matomo\Feedback\FeedbackStore;
use App\Matomo\Localization\LanguageResolver;
use Carbon\CarbonImmutable;
use Piwik\Version;
use Tests\TestCase;

class FeedbackApiTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_sends_feature_feedback_with_legacy_subject_and_body(): void
    {
        $this->allowFeedbackFor('alice');
        $this->useLanguage('de');
        $names = $this->createMock(FeedbackFeatureNameResolver::class);
        $names->expects($this->once())
            ->method('englishName')
            ->with('Lokalisierter Bericht', 'de')
            ->willReturn('Localized report');
        $this->app->instance(FeedbackFeatureNameResolver::class, $names);
        $store = $this->createStub(FeedbackStore::class);
        $store->method('emailForLogin')->with('alice')->willReturn('alice@example.test');
        $this->app->instance(FeedbackStore::class, $store);
        $mailer = $this->createMock(FeedbackMailer::class);
        $mailer->expects($this->once())->method('send')->with(
            'feedback@matomo.org',
            'alice@example.test',
            '[ Feedback Feature - Matomo ] +1 for Localized report',
            "Feature: Localized report\nLike: Yes\nChoice: useful\n".
                "Feedback:\nVery useful report\nSource: On-Premise\n".
                'Matomo '.Version::VERSION."\nURL: https://analytics.example.test/dashboard\n",
            'localhost',
        );
        $this->app->instance(FeedbackMailer::class, $mailer);

        $this->post(
            '/index.php?module=API&method=Feedback.sendFeedbackForFeature&format=json'.
            '&token_auth=user-token',
            [
                'featureName' => 'Lokalisierter Bericht',
                'like' => '1',
                'choice' => 'useful',
                'message' => '  Very useful report  ',
            ],
            ['Referer' => 'https://analytics.example.test/dashboard'],
        )->assertOk()->assertExactJson(['value' => 'success']);
    }

    public function test_returns_localized_feature_validation_without_sending_mail(): void
    {
        $this->allowFeedbackFor('alice');
        $this->useLanguage('de');
        $mailer = $this->createMock(FeedbackMailer::class);
        $mailer->expects($this->never())->method('send');
        $this->app->instance(FeedbackMailer::class, $mailer);

        $this->post(
            '/index.php?module=API&method=Feedback.sendFeedbackForFeature&format=json'.
            '&token_auth=user-token',
            ['featureName' => 'Report', 'message' => 'abc'],
        )->assertOk()->assertExactJson([
            'value' => 'Teilen Sie uns bitte im Folgenden Ihr Feedback mit.',
        ]);
    }

    public function test_redacts_auth_token_from_feedback_referrer(): void
    {
        $this->allowFeedbackFor('alice');
        $this->useLanguage('en');
        $this->app->instance(FeedbackFeatureNameResolver::class, new class implements FeedbackFeatureNameResolver
        {
            public function englishName(string $featureName, string $language): string
            {
                return $featureName;
            }
        });
        $store = $this->createStub(FeedbackStore::class);
        $store->method('emailForLogin')->willReturn('alice@example.test');
        $this->app->instance(FeedbackStore::class, $store);
        $mailer = $this->createMock(FeedbackMailer::class);
        $mailer->expects($this->once())->method('send')->with(
            'feedback@matomo.org',
            'alice@example.test',
            '[ Feedback Feature - Matomo ] +1 for Report',
            $this->callback(static fn (string $body): bool => str_contains(
                $body,
                'URL: https://analytics.example.test/?module=CoreHome&token_auth=[redacted]&idSite=1',
            ) && ! str_contains($body, 'secret-token')),
            'localhost',
        );
        $this->app->instance(FeedbackMailer::class, $mailer);

        $this->post(
            '/index.php?module=API&method=Feedback.sendFeedbackForFeature&format=json'.
            '&token_auth=user-token',
            ['featureName' => 'Report', 'like' => '1', 'message' => 'Useful report'],
            ['Referer' => 'https://analytics.example.test/?module=CoreHome&token_auth=secret-token&idSite=1'],
        )->assertOk()->assertExactJson(['value' => 'success']);
    }

    public function test_sends_survey_feedback_and_sets_six_month_reminder(): void
    {
        CarbonImmutable::setTestNow('2026-08-31 18:00:00 UTC');
        $this->allowFeedbackFor('alice');
        $this->useLanguage('en');
        $names = $this->createStub(FeedbackFeatureNameResolver::class);
        $names->method('englishName')->willReturn('What should improve?');
        $this->app->instance(FeedbackFeatureNameResolver::class, $names);
        $store = $this->createMock(FeedbackStore::class);
        $store->method('emailForLogin')->willReturn('alice@example.test');
        $store->expects($this->once())
            ->method('setNextReminder')
            ->with('alice', '2027-03-03');
        $this->app->instance(FeedbackStore::class, $store);
        $mailer = $this->createMock(FeedbackMailer::class);
        $mailer->expects($this->once())->method('send')->with(
            'feedback@matomo.org',
            'alice@example.test',
            '[ Feedback Feature - Matomo ] -1 for What should improve? (w/ feedback Survey)',
            "Question: What should improve?\nAnswer:\nBetter filters please\n".
                'Matomo '.Version::VERSION."\nURL: \n",
            'localhost',
        );
        $this->app->instance(FeedbackMailer::class, $mailer);

        $this->post(
            '/index.php?module=API&method=Feedback.sendFeedbackForSurvey&format=json'.
            '&token_auth=user-token',
            ['question' => 'What should improve?', 'message' => ' Better filters please '],
        )->assertOk()->assertExactJson(['value' => 'success']);
    }

    public function test_updates_reminder_without_requiring_site_access(): void
    {
        CarbonImmutable::setTestNow('2026-08-15 18:00:00 UTC');
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())->method('authenticatedLogin')->willReturn('alice');
        $authorizer->expects($this->never())->method('hasSomeViewAccess');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $store = $this->createMock(FeedbackStore::class);
        $store->expects($this->once())
            ->method('setNextReminder')
            ->with('alice', '2027-02-15');
        $this->app->instance(FeedbackStore::class, $store);

        $this->post(
            '/index.php?module=API&method=Feedback.updateFeedbackReminderDate'.
            '&format=json&token_auth=user-token',
        )->assertOk()->assertExactJson([
            'value' => '["Next reminder date: 2027-02-15"]',
        ]);
    }

    public function test_rejects_anonymous_feedback_before_any_side_effect(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())->method('authenticatedLogin')->willReturn('anonymous');
        $authorizer->expects($this->never())->method('hasSomeViewAccess');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $store = $this->createMock(FeedbackStore::class);
        $store->expects($this->never())->method('setNextReminder');
        $this->app->instance(FeedbackStore::class, $store);
        $mailer = $this->createMock(FeedbackMailer::class);
        $mailer->expects($this->never())->method('send');
        $this->app->instance(FeedbackMailer::class, $mailer);

        $this->post(
            '/index.php?module=API&method=Feedback.sendFeedbackForFeature&format=json',
            ['featureName' => 'Report', 'message' => 'Useful report'],
        )->assertStatus(401)->assertExactJson([
            'result' => 'error',
            'message' => 'You must be logged in to access this functionality.',
        ]);
    }

    public function test_rejects_user_without_any_view_access(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())->method('authenticatedLogin')->willReturn('alice');
        $authorizer->expects($this->once())->method('hasSomeViewAccess')->willReturn(false);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $mailer = $this->createMock(FeedbackMailer::class);
        $mailer->expects($this->never())->method('send');
        $this->app->instance(FeedbackMailer::class, $mailer);

        $this->post(
            '/index.php?module=API&method=Feedback.sendFeedbackForSurvey&format=json'.
            '&token_auth=user-token',
            ['question' => 'Question', 'message' => 'Long enough answer'],
        )->assertStatus(401)->assertExactJson([
            'result' => 'error',
            'message' => 'You must have view access to at least one website.',
        ]);
    }

    public function test_requires_feature_name_and_survey_question(): void
    {
        $this->allowFeedbackFor('alice');

        $this->post(
            '/index.php?module=API&method=Feedback.sendFeedbackForFeature&format=json',
        )->assertStatus(400)->assertJsonPath('message', "Please specify a value for 'featureName'.");

        $this->post(
            '/index.php?module=API&method=Feedback.sendFeedbackForSurvey&format=json',
        )->assertStatus(400)->assertJsonPath('message', "Please specify a value for 'question'.");
    }

    private function allowFeedbackFor(string $login): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('authenticatedLogin')->willReturn($login);
        $authorizer->method('hasSomeViewAccess')->willReturn(true);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
    }

    private function useLanguage(string $language): void
    {
        $languages = $this->createStub(LanguageResolver::class);
        $languages->method('resolve')->willReturn($language);
        $this->app->instance(LanguageResolver::class, $languages);
    }
}
