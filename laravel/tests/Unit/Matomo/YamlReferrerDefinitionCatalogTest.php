<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo;

use App\Matomo\Referrers\YamlReferrerDefinitionCatalog;
use PHPUnit\Framework\TestCase;

class YamlReferrerDefinitionCatalogTest extends TestCase
{
    public function test_matches_bundled_social_and_ai_domains(): void
    {
        $catalog = $this->catalog();

        $this->assertSame('Facebook', $catalog->socialName('https://m.facebook.com/example'));
        $this->assertSame('ChatGPT', $catalog->aiAssistantName('chat.openai.com/share/example'));
    }

    public function test_requires_domain_boundaries(): void
    {
        $catalog = $this->catalog();

        $this->assertNull($catalog->socialName('notfacebook.com'));
        $this->assertNull($catalog->aiAssistantName('fakechatgpt.com'));
    }

    private function catalog(): YamlReferrerDefinitionCatalog
    {
        $root = dirname(__DIR__, 3);

        return new YamlReferrerDefinitionCatalog(
            $root.'/vendor/matomo/searchengine-and-social-list/Socials.yml',
            $root.'/vendor/matomo/searchengine-and-social-list/AIAssistants.yml',
        );
    }
}
