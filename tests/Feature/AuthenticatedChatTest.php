<?php

namespace Tests\Feature;

use App\AI\LLM\LLMProvider;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticatedChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_utilisateur_peut_s_inscrire_et_acceder_au_chat(): void
    {
        $this->post('/register', [
            'name' => 'Awa Diop',
            'email' => 'awa@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertRedirect('/chat');

        $this->assertAuthenticated();
        $this->get('/chat')->assertOk();
    }

    public function test_le_chat_persiste_la_conversation_et_ses_messages(): void
    {
        $user = User::factory()->create(['email' => 'awa@example.com']);

        $this->app->instance(LLMProvider::class, new class implements LLMProvider
        {
            public function chat(array $messages): string
            {
                return 'Réponse de test persistée.';
            }
        });

        $response = $this->actingAs($user)->postJson('/chat', [
            'message' => 'Présente le projet TPE_MESSAGE.',
        ]);

        $response->assertOk()->assertJson([
            'answer' => 'Réponse de test persistée.',
        ]);

        $conversation = AiConversation::firstOrFail();
        $this->assertSame($user->id, $conversation->user_id);
        $this->assertSame(2, AiMessage::where('conversation_id', $conversation->id)->count());
        $this->assertDatabaseHas('ai_messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Réponse de test persistée.',
        ]);
    }

    public function test_un_visiteur_ne_peut_pas_utiliser_le_chat(): void
    {
        $this->postJson('/chat', ['message' => 'Bonjour'])
            ->assertUnauthorized();
    }
}
