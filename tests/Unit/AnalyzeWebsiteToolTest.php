<?php

namespace Tests\Unit;

use App\AI\LLM\LLMProvider;
use App\AI\Services\WebsiteScraperService;
use App\AI\Tools\AnalyzeWebsiteTool;
use App\Models\WebsiteProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyzeWebsiteToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_analyze_website_executes_and_saves_profile(): void
    {
        $mockScraper = $this->createMock(WebsiteScraperService::class);
        $mockScraper->expects($this->once())
            ->method('scrape')
            ->with('https://boulangerie-martin.fr')
            ->willReturn([
                'url' => 'https://boulangerie-martin.fr',
                'title' => 'Boulangerie Martin - Pains au levain',
                'meta_description' => 'Artisan boulanger à Lyon.',
                'site_name' => 'Boulangerie Martin',
                'headings' => [
                    'h1' => ['Boulangerie Martin'],
                    'h2' => ['Nos Pains au levain', 'Brunch du Dimanche'],
                    'h3' => [],
                ],
                'json_ld' => [
                    'business' => [
                        'name' => 'Boulangerie Martin',
                        'type' => 'Bakery',
                        'telephone' => '+33472000000',
                    ],
                ],
                'clean_text' => 'Nous proposons chaque dimanche nos formules brunch et pains au levain naturel cuits au feu de bois.',
                'summary_stats' => [
                    'word_count' => 120,
                    'has_json_ld' => true,
                    'schema_types' => ['Bakery'],
                ],
            ]);

        $mockLlm = $this->createMock(LLMProvider::class);
        $mockLlm->expects($this->once())
            ->method('chat')
            ->willReturn(json_encode([
                'business_name' => 'Boulangerie Martin',
                'activity_sector' => 'Boulangerie Artisanale',
                'key_offerings' => ['Pains au levain naturel', 'Brunch du dimanche'],
                'suggested_campaign_angles' => ['Réservation du brunch dominical'],
                'tone' => 'chaleureux et authentique',
            ]));

        $tool = new AnalyzeWebsiteTool($mockScraper, $mockLlm);

        $result = $tool->execute([
            'url' => 'https://boulangerie-martin.fr',
            'collaborator_user_id' => '01a08171-7304-7236-8991-7c58c4c86377',
        ]);

        $this->assertEquals('Boulangerie Martin', $result['business_name']);
        $this->assertEquals('Boulangerie Artisanale', $result['activity_sector']);
        $this->assertContains('Pains au levain naturel', $result['key_offerings']);
        $this->assertEquals('chaleureux et authentique', $result['brand_tone']);

        // Check database persistence in website_profiles
        $this->assertDatabaseHas('website_profiles', [
            'url' => 'https://boulangerie-martin.fr',
            'collaborator_user_id' => '01a08171-7304-7236-8991-7c58c4c86377',
            'business_name' => 'Boulangerie Martin',
            'activity_sector' => 'Boulangerie Artisanale',
            'brand_tone' => 'chaleureux et authentique',
        ]);
    }
}

