<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Feedback\FeedbackFeatureNameResolver;
use App\Matomo\Feedback\FeedbackMailer;
use App\Matomo\Feedback\FeedbackSettings;
use App\Matomo\Feedback\FeedbackStore;
use App\Matomo\Localization\LanguageResolver;
use App\Matomo\Localization\MatomoTranslator;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;
use Piwik\Version;

final readonly class FeedbackApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private FeedbackStore $feedback,
        private FeedbackMailer $mailer,
        private FeedbackFeatureNameResolver $featureNames,
        private LanguageResolver $languages,
        private MatomoTranslator $translator,
        private FeedbackSettings $settings,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->isFeedbackRequest();
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The Feedback API handler does not support this method.');
        }

        $login = $this->authorizer->authenticatedLogin($request->authentication);

        if (in_array($login, [null, '', 'anonymous'], true)) {
            return $this->responses->error(
                $request,
                'You must be logged in to access this functionality.',
                401,
            );
        }

        if ($request->method === 'Feedback.updateFeedbackReminderDate') {
            return $this->updateReminder($request, $login);
        }

        if (! $this->authorizer->hasSomeViewAccess($request->authentication)) {
            return $this->responses->error(
                $request,
                'You must have view access to at least one website.',
                401,
            );
        }

        $parameters = $request->feedback
            ?? throw new LogicException('The Feedback API parameters are missing.');
        $language = $this->languages->resolve($httpRequest, $request->authentication);

        return $request->method === 'Feedback.sendFeedbackForFeature'
            ? $this->sendFeatureFeedback($request, $httpRequest, $login, $language)
            : $this->sendSurveyFeedback($request, $httpRequest, $login, $language);
    }

    private function sendFeatureFeedback(
        ApiRequest $request,
        Request $httpRequest,
        string $login,
        string $language,
    ): Response {
        $parameters = $request->feedback
            ?? throw new LogicException('The Feedback API parameters are missing.');
        $message = $parameters->message;

        if (in_array($message, [null, '', '0', 'undefined'], true) || strlen($message) < 4) {
            return $this->responses->scalar(
                $request,
                $this->translator->translate('Feedback_FormNotEnoughFeedbackText', $language),
            );
        }

        $featureName = $this->featureNames->englishName($parameters->featureName ?? '', $language);
        $body = sprintf("Feature: %s\nLike: %s\n", $featureName, $parameters->like ? 'Yes' : 'No');

        if (! in_array($parameters->choice, [null, '', '0', 'undefined'], true)) {
            $body .= 'Choice: '.$parameters->choice."\n";
        }

        $body .= sprintf("Feedback:\n%s\n", trim($message));
        $body .= 'Source: '.$this->source($httpRequest)."\n";
        $subject = sprintf('%s for %s', $parameters->like ? '+1' : '-1', $featureName);
        $this->send($httpRequest, $login, $subject, $body);

        return $this->responses->scalar($request, 'success');
    }

    private function sendSurveyFeedback(
        ApiRequest $request,
        Request $httpRequest,
        string $login,
        string $language,
    ): Response {
        $parameters = $request->feedback
            ?? throw new LogicException('The Feedback API parameters are missing.');
        $message = $parameters->message;

        if (in_array($message, [null, '', '0'], true) || strlen($message) < 10) {
            return $this->responses->scalar(
                $request,
                $this->translator->translate('Feedback_MessageBodyValidationError', $language),
            );
        }

        $question = $this->featureNames->englishName($parameters->question ?? '', $language);
        $body = sprintf("Question: %s\nAnswer:\n%s\n", $question, trim($message));
        $subject = sprintf('-1 for %s (w/ feedback Survey)', $question);
        $this->send($httpRequest, $login, $subject, $body);
        $this->feedback->setNextReminder($login, $this->nextReminderDate());

        return $this->responses->scalar($request, 'success');
    }

    private function updateReminder(ApiRequest $request, string $login): Response
    {
        $nextReminder = $this->nextReminderDate();
        $this->feedback->setNextReminder($login, $nextReminder);

        return $this->responses->scalar(
            $request,
            json_encode(['Next reminder date: '.$nextReminder], JSON_THROW_ON_ERROR),
        );
    }

    private function send(Request $request, string $login, string $subject, string $body): void
    {
        $body .= 'Matomo '.Version::VERSION."\n";
        $body .= 'URL: '.$this->feedbackReferrer($request)."\n";

        $this->mailer->send(
            recipient: $this->settings->recipient(),
            replyTo: $this->feedback->emailForLogin($login),
            subject: '[ Feedback Feature - Matomo ] '.$subject,
            body: $body,
            host: $request->getHost(),
        );
    }

    private function source(Request $request): string
    {
        if ($request->getHost() === 'demo.matomo.cloud') {
            return 'Demo';
        }

        return defined('ABSPATH') && function_exists('add_action') ? 'Wordpress' : 'On-Premise';
    }

    private function feedbackReferrer(Request $request): string
    {
        return preg_replace(
            '/([?&]token_auth=)[^&#]*/i',
            '$1[redacted]',
            $request->headers->get('referer') ?? '',
        ) ?? '';
    }

    private function nextReminderDate(): string
    {
        return CarbonImmutable::now('UTC')
            ->startOfDay()
            ->addMonthsWithOverflow(6)
            ->format('Y-m-d');
    }
}
