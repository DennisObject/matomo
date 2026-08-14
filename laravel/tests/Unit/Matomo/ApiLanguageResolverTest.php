<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\ApiAuthentication;
use App\Matomo\Localization\ApiLanguageResolver;
use App\Matomo\Localization\LanguagePreferenceRepository;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class ApiLanguageResolverTest extends TestCase
{
    private const string SALT = 'test-salt';

    public function test_invalid_explicit_language_uses_the_configured_default(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->never())->method('authenticatedLogin');
        $preferences = $this->createMock(LanguagePreferenceRepository::class);
        $preferences->expects($this->never())->method('forLogin');
        $resolver = $this->resolver($authorizer, $preferences, defaultLanguage: 'fr');
        $request = Request::create('/index.php?language=invalid');

        $this->assertSame('fr', $resolver->resolve($request, $this->authentication()));
    }

    public function test_language_cookie_takes_priority_over_user_and_browser(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->never())->method('authenticatedLogin');
        $preferences = $this->createMock(LanguagePreferenceRepository::class);
        $preferences->expects($this->never())->method('forLogin');
        $resolver = $this->resolver($authorizer, $preferences);
        $request = Request::create('/index.php', server: ['HTTP_ACCEPT_LANGUAGE' => 'de']);
        $request->cookies->set('matomo_lang', 'language='.base64_encode('fr'));

        $this->assertSame('fr', $resolver->resolve($request, $this->authentication()));
    }

    public function test_legacy_signed_language_cookie_remains_supported(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->never())->method('authenticatedLogin');
        $preferences = $this->createMock(LanguagePreferenceRepository::class);
        $preferences->expects($this->never())->method('forLogin');
        $resolver = $this->resolver($authorizer, $preferences);
        $content = 'language='.base64_encode(serialize('fr'));
        $request = Request::create('/index.php');
        $request->cookies->set('matomo_lang', $content.':_='.sha1($content.self::SALT));

        $this->assertSame('fr', $resolver->resolve($request, $this->authentication()));
    }

    public function test_user_preference_takes_priority_over_browser_language(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())
            ->method('authenticatedLogin')
            ->willReturn('alice');
        $preferences = $this->createMock(LanguagePreferenceRepository::class);
        $preferences->expects($this->once())
            ->method('forLogin')
            ->with('alice')
            ->willReturn('fr');
        $resolver = $this->resolver($authorizer, $preferences);
        $request = Request::create('/index.php', server: ['HTTP_ACCEPT_LANGUAGE' => 'de']);

        $this->assertSame('fr', $resolver->resolve($request, $this->authentication('token')));
    }

    private function resolver(
        ApiAccessAuthorizer $authorizer,
        LanguagePreferenceRepository $preferences,
        string $defaultLanguage = 'en',
    ): ApiLanguageResolver {
        return new ApiLanguageResolver(
            authorizer: $authorizer,
            preferences: $preferences,
            availableLanguages: ['de', 'en', 'fr'],
            defaultLanguage: $defaultLanguage,
            cookieName: 'matomo_lang',
            salt: self::SALT,
        );
    }

    private function authentication(?string $token = null): ApiAuthentication
    {
        return new ApiAuthentication($token, false, false, null);
    }
}
