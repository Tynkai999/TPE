<?php

namespace Tests\Feature;

use App\AI\LLM\LLMProvider;
use App\AI\Orchestrator\AgentOrchestrator;
use App\AI\Services\WebsiteScraperService;
use App\Models\AiCommand;
use App\Models\LinkedAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AnalyzeWebsiteOrchestratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_orchestrator_analyzes_website_and_proposes_campaign_for_all_clients(): void
    {
        config(['collaborator.base_url' => 'https://collaborator.test/api/v1']);

        LinkedAccount::create([
            'collaborator_user_id' => '01a08171-7304-7236-8991-7c58c4c86377',
            'collaborator_email' => 'artisan@example.com',
            'access_token' => 'mock-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour(),
        ]);

        // Mock collaborator API returning contacts (all contacts)
        Http::fake([
            'https://collaborator.test/api/v1/contacts*' => Http::response([
                'data' => [
                    ['id' => 'client-1', 'name' => 'Alice'],
                    ['id' => 'client-2', 'name' => 'Bob'],
                    ['id' => 'client-3', 'name' => 'Charlie'],
                ],
            ]),
        ]);

        // Mock WebsiteScraperService
        $this->mock(WebsiteScraperService::class, function ($mock) {
            $mock->shouldReceive('scrape')
                ->once()
                ->with('https://fournil-lyon.fr')
                ->andReturn([
                    'url' => 'https://fournil-lyon.fr',
                    'title' => 'Le Fournil de Lyon',
                    'meta_description' => 'Boulangerie traditionnelle',
                    'site_name' => 'Le Fournil de Lyon',
                    'headings' => [
                        'h1' => ['Le Fournil de Lyon'],
                        'h2' => ['Pains au levain', 'Brunch du dimanche'],
                        'h3' => [],
                    ],
                    'json_ld' => [
                        'business' => [
                            'name' => 'Le Fournil de Lyon',
                            'type' => 'Bakery',
                        ],
                    ],
                    'clean_text' => 'Tous nos dimanches, profitez de notre formule brunch.',
                    'summary_stats' => [
                        'word_count' => 60,
                        'has_json_ld' => true,
                        'schema_types' => ['Bakery'],
                    ],
                ]);
        });

        // Mock LLM
        $this->mock(LLMProvider::class, function ($mock) {
            // 1. CommandParser LLM call
            $mock->shouldReceive('chat')
                ->andReturnUsing(function ($messages) {
                    $system = $messages[0]['content'] ?? '';
                    $user = $messages[1]['content'] ?? '';

                    // If CommandParser
                    if (str_contains($system, "analyseur d'intentions")) {
                        return json_encode([
                            'intent'   => 'analyze_website',
                            'url'      => 'https://fournil-lyon.fr',
                            'audience' => 'all',
                            'channel'  => 'sms',
                        ]);
                    }

                    // If AnalyzeWebsiteTool
                    if (str_contains($system, 'analyste marketing expert')) {
                        return json_encode([
                            'business_name' => 'Le Fournil de Lyon',
                            'activity_sector' => 'Boulangerie Artisanale',
                            'key_offerings' => ['Pains au levain naturel', 'Brunch du dimanche'],
                            'suggested_campaign_angles' => ['Dégustation brunch dominical'],
                            'tone' => 'chaleureux et convivial',
                        ]);
                    }

                    // If GenerateMessageTool
                    if (str_contains($system, 'rédacteur publicitaire') || str_contains($system, 'directeur artistique')) {
                        return 'Bonjour ! Venez savourer notre brunch artisanal au Fournil de Lyon ce dimanche. Réservation conseillée !';
                    }

                    return 'OK';
                });
        });

        $orchestrator = app(AgentOrchestrator::class);
        $response = $orchestrator->handle(
            'Voici mon site https://fournil-lyon.fr, prépare une campagne pour tous mes clients',
            '01a08171-7304-7236-8991-7c58c4c86377'
        );

        $this->assertStringContainsString('Le Fournil de Lyon', $response);
        $this->assertStringContainsString('3 contacts', $response);
        $this->assertStringContainsString("l'ensemble de vos clients", $response);
        $this->assertStringContainsString('Proposition de campagne #', $response);
        $this->assertStringContainsString('brunch artisanal', $response);

        // Verify AiCommand creation
        $command = AiCommand::first();
        $this->assertNotNull($command);
        $this->assertEquals('PROPOSED', $command->status);
        $this->assertEquals(3, $command->estimated_recipients);
        $this->assertEquals('01a08171-7304-7236-8991-7c58c4c86377', $command->collaborator_user_id);
        $this->assertEquals(['client-1', 'client-2', 'client-3'], $command->parameters['contact_ids']);
    }
}

