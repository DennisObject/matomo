<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo;

use App\Matomo\Api\OptOutEmbedRequest;
use App\Matomo\CoreAdmin\TranslatedOptOutEmbedCodeGenerator;
use App\Matomo\Localization\MatomoTranslator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class TranslatedOptOutEmbedCodeGeneratorTest extends TestCase
{
    public function test_generates_an_encoded_javascript_embed_for_a_trusted_host(): void
    {
        $generator = $this->generator(['analytics.example']);
        $code = $generator->javascript(new OptOutEmbedRequest(
            backgroundColor: 'fff',
            fontColor: '123456',
            fontSize: '1.2em',
            fontFamily: 'Arial, sans-serif',
            applyStyling: true,
            showIntro: false,
            matomoUrl: 'https://sub.analytics.example/stats/',
            language: 'en-US',
        ));

        $this->assertSame(
            '<div id="matomo-opt-out"></div>'."\n"
            .'<script src="https://sub.analytics.example/stats/index.php?module=CoreAdminHome&amp;action=optOutJS'
            .'&amp;divId=matomo-opt-out&amp;language=en-US&amp;backgroundColor=fff&amp;fontColor=123456'
            .'&amp;fontSize=1.2em&amp;fontFamily=Arial%2C%20sans-serif&amp;showIntro=0"></script>',
            $code,
        );
    }

    public function test_rejects_unsafe_or_untrusted_javascript_embed_urls(): void
    {
        $generator = $this->generator(['analytics.example']);
        $options = new OptOutEmbedRequest('', '', '', '', false, true, language: 'en');

        foreach ([
            'javascript://analytics.example',
            'https://evil.example',
            'https://analytics.example/?redirect=https://evil.example',
            'https://user:password@analytics.example',
        ] as $url) {
            try {
                $generator->javascript(new OptOutEmbedRequest(
                    $options->backgroundColor,
                    $options->fontColor,
                    $options->fontSize,
                    $options->fontFamily,
                    $options->applyStyling,
                    $options->showIntro,
                    matomoUrl: $url,
                    language: $options->language,
                ));
                $this->fail("The URL [{$url}] should be rejected.");
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('The provided URL is invalid.', $exception->getMessage());
            }
        }
    }

    public function test_generates_a_self_contained_embed_with_validated_styles_and_safe_json(): void
    {
        $generator = $this->generator([]);
        $code = $generator->selfContained(new OptOutEmbedRequest(
            backgroundColor: 'ffffff',
            fontColor: '123',
            fontSize: '15px',
            fontFamily: '"Open Sans", sans-serif',
            applyStyling: true,
            showIntro: true,
            cookiePath: '/privacy',
            cookieDomain: '</script><script>alert(1)</script>',
            cookieSameSite: 'Strict',
        ), 'fr');

        $this->assertStringContainsString(
            'style="font-size: 15px; font-family: &quot;Open Sans&quot;, sans-serif; color: #123; background-color: #ffffff; "',
            $code,
        );
        $this->assertStringContainsString('"cookiePath":"/privacy"', $code);
        $this->assertStringContainsString('"cookieDomain":"\\u003C/script\\u003E', $code);
        $this->assertStringNotContainsString('</script><script>alert(1)</script>', $code);
        $this->assertStringContainsString('translated:CoreAdminHome_OptOutComplete:fr', $code);
        $this->assertStringContainsString('window.MatomoConsent = {', $code);
        $this->assertStringContainsString("CONSENT_COOKIE_NAME: 'mtm_consent'", $code);
    }

    public function test_rejects_invalid_self_contained_styles(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("backgroundColor value of 'red; color: blue' is not valid");

        $this->generator([])->selfContained(new OptOutEmbedRequest(
            backgroundColor: 'red; color: blue',
            fontColor: '',
            fontSize: '',
            fontFamily: '',
            applyStyling: true,
            showIntro: true,
        ), 'en');
    }

    /** @param list<string> $trustedHosts */
    private function generator(array $trustedHosts): TranslatedOptOutEmbedCodeGenerator
    {
        return new TranslatedOptOutEmbedCodeGenerator(
            new class implements MatomoTranslator
            {
                public function translate(string $key, string $language, array $arguments = []): string
                {
                    return "translated:{$key}:{$language}";
                }
            },
            $trustedHosts,
            true,
        );
    }
}
