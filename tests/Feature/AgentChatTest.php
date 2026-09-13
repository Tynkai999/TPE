<?php

namespace Tests\Feature;

use App\AI\LLM\LLMProvider;
use App\Models\AiCommand;
use App\Models\LinkedAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AgentChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_repond_a_message_simple(): void
    {
        $this->artisan('agent:chat', ['message' => 'Dis simplement bonjour je suis de TPE'])
            ->assertExitCode(0);
    }

    public function test_recherche_les_clients_inactifs_via_le_tool(): void
    {
        config(['collaborator.base_url' => 'https://collaborator.test/api/v1']);

        LinkedAccount::create([
            'collaborator_user_id' => '01a08171-7304-7236-8991-7c58c4c86377',
            'collaborator_email' => 'test@example.com',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour(),
        ]);

        Http::fake([
            'https://collaborator.test/*' => Http::response([
                'data' => [
                    ['id' => 'contact-1'],
                    ['id' => 'contact-2'],
                ],
            ]),
        ]);

        $this->artisan('agent:chat', [
            'message' => 'Trouve mes clients inactifs depuis 30 jours.',
            '--collaborator-user' => '01a08171-7304-7236-8991-7c58c4c86377',
        ])
            ->expectsOutput("Agent : J'ai trouvé 2 contacts inactifs depuis 30 jours.")
            ->assertExitCode(0);

        Http::assertSent(function ($request): bool {
            return str_starts_with($request->url(), 'https://collaborator.test/api/v1/contacts')
                && $request->header('Authorization')[0] === 'Bearer access-token'
                && $request->data()['inactive_days'] === 30;
        });
    }

    public function test_comprend_une_formulation_libre_via_le_parser_llm(): void
    {
        config(['collaborator.base_url' => 'https://collaborator.test/api/v1']);

        LinkedAccount::create([
            'collaborator_user_id' => '01a08171-7304-7236-8991-7c58c4c86377',
            'collaborator_email' => 'test@example.com',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour(),
        ]);

        $this->app->instance(LLMProvider::class, new class implements LLMProvider
        {
            public function chat(array $messages): string
            {
                return '{"intent":"search_contacts","inactive_days":30}';
            }
        });

        Http::fake([
            'https://collaborator.test/*' => Http::response([
                'data' => [['id' => 'contact-1']],
            ]),
        ]);

        $this->artisan('agent:chat', [
            'message' => "Quels clients n'ont plus commandé depuis environ 30 jours ?",
            '--collaborator-user' => '01a08171-7304-7236-8991-7c58c4c86377',
        ])
            ->expectsOutput("Agent : J'ai trouvé 1 contacts inactifs depuis 30 jours.")
            ->assertExitCode(0);
    }

    public function test_refuse_une_recherche_sans_contexte_collaborateur(): void
    {
        $this->artisan('agent:chat', [
            'message' => 'Trouve mes clients inactifs depuis 30 jours.',
        ])
            ->expectsOutput('Agent : Votre compte local n’est pas encore relié à un compte collaborateur. Reliez-le avant de demander vos contacts.')
            ->assertExitCode(0);
    }

    public function test_cree_une_proposition_sans_envoyer_la_campagne(): void
    {
        config(['collaborator.base_url' => 'https://collaborator.test/api/v1']);

        LinkedAccount::create([
            'collaborator_user_id' => '01a08171-7304-7236-8991-7c58c4c86377',
            'collaborator_email' => 'test@example.com',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour(),
        ]);

        $this->app->instance(LLMProvider::class, new class implements LLMProvider
        {
            public function chat(array $messages): string
            {
                return 'Profitez de 15% de réduction sur votre prochaine commande.';
            }
        });

        Http::fake([
            'https://collaborator.test/*' => Http::response([
                'data' => [
                    ['id' => 'contact-1'],
                    ['id' => 'contact-2'],
                ],
            ]),
        ]);

        $this->artisan('agent:chat', [
            'message' => 'Envoie une promotion de 15% à mes clients inactifs depuis 30 jours.',
            '--collaborator-user' => '01a08171-7304-7236-8991-7c58c4c86377',
        ])
            ->expectsOutputToContain('Voulez-vous confirmer cette campagne ?')
            ->assertExitCode(0);

        $this->assertDatabaseHas('ai_commands', [
            'collaborator_user_id' => '01a08171-7304-7236-8991-7c58c4c86377',
            'intent' => 'DRAFT_MESSAGE',
            'status' => 'PROPOSED',
            'estimated_recipients' => 2,
        ]);

        $this->assertSame(1, AiCommand::count());
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/campaigns'));
    }

    public function test_confirme_une_proposition_pour_le_bon_compte(): void
    {
        $command = AiCommand::create([
            'collaborator_user_id' => '01a08171-7304-7236-8991-7c58c4c86377',
            'intent' => 'DRAFT_MESSAGE',
            'parameters' => [
                'message' => 'Message proposé',
                'contact_ids' => ['contact-1', 'contact-2'],
                'message_channel' => 'sms',
            ],
            'status' => 'PROPOSED',
            'estimated_recipients' => 2,
        ]);

        config(['collaborator.base_url' => 'https://collaborator.test/api/v1']);
        LinkedAccount::create([
            'collaborator_user_id' => '01a08171-7304-7236-8991-7c58c4c86377',
            'collaborator_email' => 'test@example.com',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour(),
        ]);
        Http::fake([
            'https://collaborator.test/*' => Http::sequence()
                ->push(['data' => []])
                ->push(['data' => ['id' => 'group-1']])
                ->push(['data' => []])
                ->push(['data' => ['id' => 'campaign-1']]),
        ]);

        $this->artisan('agent:confirm', [
            'aiCommand' => $command->id,
            '--collaborator-user' => '01a08171-7304-7236-8991-7c58c4c86377',
        ])
            ->expectsOutput("Proposition #{$command->id} confirmée.")
            ->assertExitCode(0);

        $this->assertDatabaseHas('ai_commands', [
            'id' => $command->id,
            'status' => 'CONFIRMED',
            'campaign_id' => 'campaign-1',
        ]);
    }

    public function test_refuse_la_confirmation_par_un_autre_compte(): void
    {
        $command = AiCommand::create([
            'collaborator_user_id' => '01a08171-7304-7236-8991-7c58c4c86377',
            'intent' => 'DRAFT_MESSAGE',
            'parameters' => ['message' => 'Message proposé'],
            'status' => 'PROPOSED',
        ]);

        $this->artisan('agent:confirm', [
            'aiCommand' => $command->id,
            '--collaborator-user' => '01b08171-7304-7236-8991-7c58c4c86377',
        ])
            ->expectsOutput('Proposition introuvable ou non autorisée.')
            ->assertExitCode(1);

        $this->assertDatabaseHas('ai_commands', [
            'id' => $command->id,
            'status' => 'PROPOSED',
        ]);
    }

    public function test_envoie_une_campagne_confirmee(): void
    {
        config(['collaborator.base_url' => 'https://collaborator.test/api/v1']);
        LinkedAccount::create([
            'collaborator_user_id' => '01a08171-7304-7236-8991-7c58c4c86377',
            'collaborator_email' => 'test@example.com',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour(),
        ]);
        $command = AiCommand::create([
            'collaborator_user_id' => '01a08171-7304-7236-8991-7c58c4c86377',
            'intent' => 'DRAFT_MESSAGE',
            'parameters' => ['message' => 'Message proposé'],
            'status' => 'CONFIRMED',
            'campaign_id' => 'campaign-1',
        ]);

        Http::fake([
            'https://collaborator.test/api/v1/campaigns/campaign-1/send' => Http::response([
                'success' => true,
                'data' => ['id' => 'campaign-1', 'status' => 'sent'],
            ]),
        ]);

        $this->artisan('agent:send', [
            'aiCommand' => $command->id,
            '--collaborator-user' => '01a08171-7304-7236-8991-7c58c4c86377',
        ])
            ->expectsOutput('Campagne campaign-1 envoyée.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('ai_commands', [
            'id' => $command->id,
            'status' => 'EXECUTED',
        ]);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/campaigns/campaign-1/send'));
    }
}