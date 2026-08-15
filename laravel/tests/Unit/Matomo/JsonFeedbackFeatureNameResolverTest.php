<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo;

use App\Matomo\Feedback\JsonFeedbackFeatureNameResolver;
use App\Matomo\Localization\JsonMatomoTranslator;
use PHPUnit\Framework\TestCase;

class JsonFeedbackFeatureNameResolverTest extends TestCase
{
    public function test_resolves_a_localized_label_back_to_english(): void
    {
        $directories = [dirname(__DIR__, 4).'/plugins/Feedback/lang'];
        $resolver = new JsonFeedbackFeatureNameResolver(
            $directories,
            new JsonMatomoTranslator($directories),
        );

        $this->assertSame(
            'What are your biggest problems or pain points with Matomo and why?',
            $resolver->englishName(
                'Was sind Ihre größten Probleme oder Pain Points (Schmerzpunkte) mit Matomo und warum?',
                'de',
            ),
        );
        $this->assertSame('Unknown label', $resolver->englishName('Unknown label', 'de'));
        $this->assertSame('English label', $resolver->englishName('English label', 'en'));
    }
}
