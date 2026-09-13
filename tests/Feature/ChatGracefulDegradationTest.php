<?php

namespace Tests\Feature;

use App\AI\Exceptions\LLMUnavailableException;
use App\AI\Orchestrator\AgentOrchestrator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatGracefulDegradationTest extends TestCase
{
    use RefreshDatabase;

    public function test_chat_controller_returns_friendly_message_when_llm_is_offline(): void
    {
        $user = User::factory()->create();

        // Simulate LM Studio offline in orchestrator
        $this->mock(AgentOrchestrator::class, function ($mock) {
            $mock->shouldReceive('handle')
                ->once()
                ->andThrow(new LLMUnavailableException("Serveur local non joignable"));
        });

        $response = $this->actingAs($user)->postJson('/chat', [
            'message' => 'Bonjour, analyse mon activité',
        ]);

        // Must NOT be 500 error! Must be 200 OK with friendly answer.
        $response->assertOk();
        $this->assertStringContainsString('LM Studio est bien démarré', $response->json('answer'));
        $this->assertNotNull($response->json('conversation_id'));

        // Conversation must be persisted
        $this->assertDatabaseHas('ai_messages', [
            'role' => 'user',
            'content' => 'Bonjour, analyse mon activité',
        ]);
        $this->assertDatabaseHas('ai_messages', [
            'role' => 'assistant',
        ]);
    }

    public function test_message_api_controller_returns_service_unavailable_json_when_llm_is_offline(): void
    {
        $user = User::factory()->create();
        \App\Models\LinkedAccount::create([
            'user_id' => $user->id,
            'collaborator_user_id' => '01a08171-7304-7236-8991-7c58c4c86377',
            'collaborator_email' => 'artisan@example.com',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour(),
        ]);

        $this->mock(AgentOrchestrator::class, function ($mock) {
            $mock->shouldReceive('handle')
                ->once()
                ->andThrow(new LLMUnavailableException("LM Studio éteint"));
        });

        $response = $this->actingAs($user, 'api')->postJson('/api/v1/message', [
            'text' => 'Crée une campagne pour mes clients',
        ]);

        $response->assertStatus(503);
        $this->assertFalse($response->json('success'));
        $this->assertStringContainsString('LM Studio est éteint', $response->json('message'));
    }
}
