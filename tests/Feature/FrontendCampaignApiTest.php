<?php

namespace Tests\Feature;

use App\Models\AiCommand;
use App\Models\LinkedAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FrontendCampaignApiTest extends TestCase
{
    use RefreshDatabase;

    private const USER_ID = '01a08171-7304-7236-8991-7c58c4c86377';

    public function test_un_utilisateur_peut_lier_son_compte_collaborateur(): void
    {
        config(['collaborator.base_url' => 'https://collaborator.test/api/v1']);
        Http::fake([
            'https://collaborator.test/*' => Http::response([
                'access_token' => 'access-token',
                'refresh_token' => 'refresh-token',
                'expires_in' => 3600,
                'user' => ['id' => self::USER_ID, 'email' => 'api@example.com'],
            ]),
        ]);
        $user = User::factory()->create(['email' => 'local@example.com']);

        $this->actingAs($user)->postJson('/collaborator-account', [
            'email' => 'api@example.com',
            'password' => 'password123',
        ])->assertCreated()->assertJsonPath('account.collaborator_user_id', self::USER_ID);

        $this->assertDatabaseHas('linked_accounts', [
            'user_id' => $user->id,
            'collaborator_user_id' => self::USER_ID,
        ]);
    }

    public function test_le_frontend_peut_confirmer_une_campagne_email_ciblee(): void
    {
        $user = User::factory()->create();
        $this->linkAccount($user);
        $command = AiCommand::create([
            'collaborator_user_id' => self::USER_ID,
            'intent' => 'DRAFT_MESSAGE',
            'parameters' => [
                'message' => 'Découvrez notre offre.',
                'contact_ids' => ['contact-1'],
                'message_channel' => 'email',
            ],
            'status' => 'PROPOSED',
            'estimated_recipients' => 1,
        ]);

        Http::fake([
            'https://collaborator.test/*' => Http::sequence()
                ->push(['data' => []])
                ->push(['data' => ['id' => 'group-1']])
                ->push(['data' => []])
                ->push(['data' => ['id' => 'campaign-1']]),
        ]);

        $this->actingAs($user)->postJson("/ai-commands/{$command->id}/confirm")
            ->assertOk()
            ->assertJson(['status' => 'CONFIRMED', 'campaign_id' => 'campaign-1']);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://collaborator.test/api/v1/campaigns'
            && $request['channels'] === ['email']
            && $request['group_id'] === 'group-1'
        );
    }

    public function test_un_autre_utilisateur_ne_peut_pas_confirmer(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->linkAccount($owner);
        $command = AiCommand::create([
            'collaborator_user_id' => self::USER_ID,
            'intent' => 'DRAFT_MESSAGE',
            'parameters' => ['message' => 'Offre', 'contact_ids' => ['contact-1']],
            'status' => 'PROPOSED',
        ]);

        $this->actingAs($other)->postJson("/ai-commands/{$command->id}/confirm")
            ->assertNotFound();
    }

    public function test_le_frontend_peut_annuler_puis_envoyer_une_campagne_confirmee(): void
    {
        $user = User::factory()->create();
        $this->linkAccount($user);
        $cancelled = AiCommand::create([
            'collaborator_user_id' => self::USER_ID,
            'intent' => 'DRAFT_MESSAGE',
            'parameters' => [],
            'status' => 'PROPOSED',
        ]);

        $this->actingAs($user)->postJson("/ai-commands/{$cancelled->id}/cancel")
            ->assertOk()->assertJson(['status' => 'CANCELLED']);

        $sent = AiCommand::create([
            'collaborator_user_id' => self::USER_ID,
            'intent' => 'DRAFT_MESSAGE',
            'parameters' => [],
            'status' => 'CONFIRMED',
            'campaign_id' => 'campaign-2',
        ]);
        Http::fake([
            'https://collaborator.test/*' => Http::response(['success' => true, 'data' => []]),
        ]);

        $this->actingAs($user)->postJson("/ai-commands/{$sent->id}/send")
            ->assertOk()->assertJson(['status' => 'EXECUTED']);

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/campaigns/campaign-2/send'));
    }

    private function linkAccount(User $user): void
    {
        config(['collaborator.base_url' => 'https://collaborator.test/api/v1']);
        LinkedAccount::create([
            'user_id' => $user->id,
            'collaborator_user_id' => self::USER_ID,
            'collaborator_email' => 'api@example.com',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour(),
        ]);
    }
}
