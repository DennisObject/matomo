<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\ProfessionalServices\PromoWidgetDismissalRepository;
use Tests\TestCase;

class ProfessionalServicesApiTest extends TestCase
{
    public function test_dismisses_known_widget_for_authenticated_user(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('authenticatedLogin')->willReturn('alice');
        $dismissals = $this->createMock(PromoWidgetDismissalRepository::class);
        $dismissals->expects($this->once())
            ->method('dismiss')
            ->with('alice', 'PromoFunnels', $this->isType('int'));
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(PromoWidgetDismissalRepository::class, $dismissals);

        $this->post(
            '/index.php?module=API&method=ProfessionalServices.dismissWidget'.
            '&widgetName=PromoFunnels&format=json&token_auth=user-token',
        )->assertOk()->assertExactJson(['value' => true]);
    }

    public function test_rejects_unknown_widget_without_writing(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('authenticatedLogin')->willReturn('alice');
        $dismissals = $this->createMock(PromoWidgetDismissalRepository::class);
        $dismissals->expects($this->never())->method('dismiss');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(PromoWidgetDismissalRepository::class, $dismissals);

        $this->post(
            '/index.php?module=API&method=ProfessionalServices.dismissWidget'.
            '&widgetName=UnknownWidget&format=json&token_auth=user-token',
        )->assertStatus(400)->assertExactJson([
            'result' => 'error',
            'message' => "Can't dismiss unknown widget UnknownWidget",
        ]);
    }

    public function test_rejects_anonymous_user_without_writing(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('authenticatedLogin')->willReturn('anonymous');
        $dismissals = $this->createMock(PromoWidgetDismissalRepository::class);
        $dismissals->expects($this->never())->method('dismiss');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(PromoWidgetDismissalRepository::class, $dismissals);

        $this->post(
            '/index.php?module=API&method=ProfessionalServices.dismissWidget'.
            '&widgetName=PromoFunnels&format=json',
        )->assertStatus(401)->assertExactJson([
            'result' => 'error',
            'message' => 'You must be logged in to access this functionality.',
        ]);
    }
}
