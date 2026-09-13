<?php

namespace Tests\Feature;

use App\AI\LLM\LLMProvider;
use App\Models\AiCommand;
use App\Models\LinkedAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Parcours utilisateur bout-en-bout via les routes HTTP réellement exposées au frontend.
 *
 * Scénarios couverts :
 *  1. Authentification (connexion / accès refusé si non connecté)
 *  2. Listing des contacts
 *  3. Génération d'une proposition de campagne
 *  4. Envoi et annulation d'une campagne par canal + reprise idempotente
 *  5. Campagne pour un public ciblé
 *
 * Formats de réponse mockés :
 *  - Format simple  : {data: [{id:...}, ...]}
 *  - Format réel    : {data: {contacts: {data: [{id:...}, ...]}}} (pagination imbriquée)
 */
class UserJourneyTest extends TestCase
{
    use RefreshDatabase;

    private const USER_ID = '01a08171-7304-7236-8991-7c58c4c86377';

    // ──────────────────────────────────────────────────────────────────────────
    // 1. AUTHENTIFICATION
    // ──────────────────────────────────────────────────────────────────────────

    public function test_1_un_utilisateur_non_connecte_ne_peut_pas_utiliser_le_chat(): void
    {
        $this->postJson('/chat', ['message' => 'Liste mes contacts'])
            ->assertUnauthorized();
    }

    public function test_1_un_utilisateur_connecte_accede_au_chat(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/chat')->assertOk();
    }

    public function test_1_un_utilisateur_peut_sinscrire_et_etre_redirige_vers_le_chat(): void
    {
        $this->post('/register', [
            'name'                  => 'Marie Dupont',
            'email'                 => 'marie@example.com',
            'password'              => 'motdepasse123',
            'password_confirmation' => 'motdepasse123',
        ])->assertRedirect('/chat');

        $this->assertAuthenticated();
    }

    public function test_1_la_connexion_avec_de_mauvais_identifiants_echoue(): void
    {
        User::factory()->create(['email' => 'valide@example.com']);

        $this->post('/login', [
            'email'    => 'valide@example.com',
            'password' => 'mauvais-motdepasse',
        ])->assertRedirect();

        $this->assertGuest();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 2. LISTE DES CONTACTS
    // ──────────────────────────────────────────────────────────────────────────

    /** Format simple {data: [...]} (mocks / petits endpoints) */
    public function test_2_un_utilisateur_connecte_peut_lister_ses_contacts(): void
    {
        $user = User::factory()->create();
        $this->linkAccount($user);

        Http::fake([
            'https://collaborator.test/*' => Http::response([
                'data' => [
                    ['id' => 'contact-1'],
                    ['id' => 'contact-2'],
                    ['id' => 'contact-3'],
                ],
            ]),
        ]);

        $this->actingAs($user)->postJson('/chat', ['message' => 'Liste mes contacts'])
            ->assertOk()
            ->assertJson(['answer' => "J'ai trouvé 3 contacts dans votre compte collaborateur."]);

        Http::assertSent(fn ($r): bool => str_starts_with($r->url(), 'https://collaborator.test/api/v1/contacts'));
    }

    /** Format imbriqué réel {data: {contacts: {data: [...]}}} (API collaborateur en production) */
    public function test_2_extrait_correctement_les_contacts_format_imbrique_reel(): void
    {
        $user = User::factory()->create();
        $this->linkAccount($user);

        Http::fake([
            'https://collaborator.test/*' => Http::response([
                'data' => [
                    'contacts' => [
                        'data' => [
                            ['id' => 'uuid-001'],
                            ['id' => 'uuid-002'],
                            ['id' => 'uuid-003'],
                            ['id' => 'uuid-004'],
                            ['id' => 'uuid-005'],
                            ['id' => 'uuid-006'],
                            ['id' => 'uuid-007'],
                        ],
                    ],
                ],
            ]),
        ]);

        $this->actingAs($user)->postJson('/chat', ['message' => 'Liste mes contacts'])
            ->assertOk()
            ->assertJson(['answer' => "J'ai trouvé 7 contacts dans votre compte collaborateur."]);
    }

    public function test_2_sans_compte_collaborateur_lie_le_chat_retourne_un_message_clair(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/chat', [
            'message' => 'Trouve mes clients inactifs depuis 30 jours.',
        ])->assertOk();

        $this->assertStringContainsString('pas encore relié à un compte collaborateur', $response->json('answer'));
        $this->assertStringContainsString('Reliez-le avant de demander vos contacts', $response->json('answer'));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 3. GÉNÉRATION D'UNE PROPOSITION DE CAMPAGNE
    // ──────────────────────────────────────────────────────────────────────────

    public function test_3_un_utilisateur_connecte_peut_generer_une_proposition_de_campagne(): void
    {
        $user = User::factory()->create();
        $this->linkAccount($user);
        $this->fakeLlmMessage('Profitez de 15% de réduction sur votre prochaine commande.');

        Http::fake([
            'https://collaborator.test/*' => Http::response([
                'data' => [
                    ['id' => 'contact-1'],
                    ['id' => 'contact-2'],
                ],
            ]),
        ]);

        $response = $this->actingAs($user)->postJson('/chat', [
            'message' => 'Envoie une promotion de 15% à mes clients inactifs depuis 30 jours.',
        ])->assertOk();

        $this->assertStringContainsString('Voulez-vous confirmer cette campagne ?', $response->json('answer'));
        $this->assertDatabaseHas('ai_commands', [
            'collaborator_user_id' => self::USER_ID,
            'intent'               => 'DRAFT_MESSAGE',
            'status'               => 'PROPOSED',
            'estimated_recipients' => 2,
        ]);
        // La campagne ne doit PAS être créée côté API avant confirmation
        Http::assertNotSent(fn ($r): bool => str_contains($r->url(), '/campaigns'));
    }

    /** Vérifie que les contact_ids sont bien enregistrés dans les paramètres de la proposition */
    public function test_3_la_proposition_contient_les_ids_de_contacts(): void
    {
        $user = User::factory()->create();
        $this->linkAccount($user);
        $this->fakeLlmMessage('Super offre.');

        Http::fake([
            'https://collaborator.test/*' => Http::response([
                'data' => [
                    ['id' => 'cid-aaa'],
                    ['id' => 'cid-bbb'],
                ],
            ]),
        ]);

        $this->actingAs($user)->postJson('/chat', [
            'message' => 'Envoie une promotion de 10% à mes clients inactifs depuis 14 jours.',
        ])->assertOk();

        $command = AiCommand::latest('id')->first();
        $this->assertEqualsCanonicalizing(['cid-aaa', 'cid-bbb'], $command->parameters['contact_ids']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 4. ENVOI / ANNULATION PAR CANAL
    // ──────────────────────────────────────────────────────────────────────────

    public function test_4_un_utilisateur_connecte_peut_envoyer_une_campagne_par_canal_whatsapp(): void
    {
        $user = User::factory()->create();
        $this->linkAccount($user);
        $command = $this->makeCommand($user, 'whatsapp');

        Http::fake([
            'https://collaborator.test/*' => Http::sequence()
                ->push(['data' => []])                           // GET /opt-outs (aucun)
                ->push(['data' => ['id' => 'group-wa']])        // POST /groups
                ->push(['data' => []])                           // POST /groups/{id}/contacts
                ->push(['data' => ['id' => 'campaign-wa']])     // POST /campaigns
                ->push(['success' => true, 'data' => ['id' => 'campaign-wa', 'status' => 'sent']]), // POST /campaigns/{id}/send
        ]);

        // Confirmation
        $this->actingAs($user)->postJson("/ai-commands/{$command->id}/confirm")
            ->assertOk()
            ->assertJson(['status' => 'CONFIRMED', 'campaign_id' => 'campaign-wa']);

        Http::assertSent(fn ($r): bool =>
            $r->url() === 'https://collaborator.test/api/v1/campaigns'
            && $r['channels'] === ['whatsapp']
        );

        // Envoi réel
        $this->actingAs($user)->postJson("/ai-commands/{$command->id}/send")
            ->assertOk()
            ->assertJson(['status' => 'EXECUTED']);

        Http::assertSent(fn ($r): bool => str_ends_with($r->url(), '/campaigns/campaign-wa/send'));
        $this->assertDatabaseHas('ai_commands', ['id' => $command->id, 'status' => 'EXECUTED']);
    }

    public function test_4_un_utilisateur_connecte_peut_envoyer_une_campagne_par_canal_sms(): void
    {
        $user = User::factory()->create();
        $this->linkAccount($user);
        $command = $this->makeCommand($user, 'sms');

        Http::fake([
            'https://collaborator.test/*' => Http::sequence()
                ->push(['data' => []])                           // GET /opt-outs
                ->push(['data' => ['id' => 'group-sms']])       // POST /groups
                ->push(['data' => []])                           // POST /groups/{id}/contacts
                ->push(['data' => ['id' => 'campaign-sms']])    // POST /campaigns
                ->push(['success' => true, 'data' => []]),      // POST /campaigns/{id}/send
        ]);

        $this->actingAs($user)->postJson("/ai-commands/{$command->id}/confirm")
            ->assertOk()
            ->assertJson(['status' => 'CONFIRMED', 'campaign_id' => 'campaign-sms']);

        Http::assertSent(fn ($r): bool =>
            $r->url() === 'https://collaborator.test/api/v1/campaigns'
            && $r['channels'] === ['sms']
        );

        $this->actingAs($user)->postJson("/ai-commands/{$command->id}/send")
            ->assertOk()->assertJson(['status' => 'EXECUTED']);
    }

    public function test_4_un_utilisateur_connecte_peut_envoyer_une_campagne_par_canal_email(): void
    {
        $user = User::factory()->create();
        $this->linkAccount($user);
        $command = $this->makeCommand($user, 'email');

        Http::fake([
            'https://collaborator.test/*' => Http::sequence()
                ->push(['data' => []])                           // GET /opt-outs
                ->push(['data' => ['id' => 'group-email']])     // POST /groups
                ->push(['data' => []])                           // POST /groups/{id}/contacts
                ->push(['data' => ['id' => 'campaign-email']]), // POST /campaigns
        ]);

        $this->actingAs($user)->postJson("/ai-commands/{$command->id}/confirm")
            ->assertOk()
            ->assertJson(['status' => 'CONFIRMED', 'campaign_id' => 'campaign-email']);
    }

    public function test_4_un_utilisateur_connecte_peut_annuler_une_campagne_proposee(): void
    {
        $user = User::factory()->create();
        $this->linkAccount($user);
        $command = AiCommand::create([
            'collaborator_user_id' => self::USER_ID,
            'intent'               => 'DRAFT_MESSAGE',
            'parameters'           => ['message' => 'Offre', 'contact_ids' => ['contact-1']],
            'status'               => 'PROPOSED',
        ]);

        $this->actingAs($user)->postJson("/ai-commands/{$command->id}/cancel")
            ->assertOk()
            ->assertJson(['status' => 'CANCELLED']);

        $this->assertDatabaseHas('ai_commands', ['id' => $command->id, 'status' => 'CANCELLED']);

        // On ne peut plus envoyer une campagne annulée
        $this->actingAs($user)->postJson("/ai-commands/{$command->id}/send")
            ->assertStatus(409);
    }

    /** Les contacts ayant fait un opt-out sont exclus avant la création de la campagne */
    public function test_4_les_contacts_opted_out_sont_exclus_de_la_campagne(): void
    {
        $user = User::factory()->create();
        $this->linkAccount($user);
        $command = AiCommand::create([
            'collaborator_user_id' => self::USER_ID,
            'intent'               => 'DRAFT_MESSAGE',
            'parameters'           => [
                'message'         => 'Offre',
                'contact_ids'     => ['contact-keep', 'contact-opted-out'],
                'message_channel' => 'sms',
            ],
            'status'               => 'PROPOSED',
            'estimated_recipients' => 2,
        ]);

        Http::fake([
            'https://collaborator.test/*' => Http::sequence()
                // GET /opt-outs : contact-opted-out a demandé à être retiré
                ->push(['data' => ['opt_outs' => [['contact_id' => 'contact-opted-out']]]])
                ->push(['data' => ['id' => 'group-filtered']])         // POST /groups
                ->push(['data' => []])                                  // POST /groups/{id}/contacts
                ->push(['data' => ['id' => 'campaign-filtered']]),     // POST /campaigns
        ]);

        $this->actingAs($user)->postJson("/ai-commands/{$command->id}/confirm")
            ->assertOk()
            ->assertJson(['status' => 'CONFIRMED']);

        // Vérifie que seul contact-keep est envoyé à /groups/{id}/contacts
        Http::assertSent(fn ($r): bool =>
            str_ends_with($r->url(), '/contacts')
            && $r['contact_ids'] === ['contact-keep']
        );
    }

    /** Si tous les contacts sont opted-out, la confirmation doit échouer proprement */
    public function test_4_echec_si_tous_les_contacts_ont_fait_un_opt_out(): void
    {
        $user = User::factory()->create();
        $this->linkAccount($user);
        $command = AiCommand::create([
            'collaborator_user_id' => self::USER_ID,
            'intent'               => 'DRAFT_MESSAGE',
            'parameters'           => [
                'message'         => 'Offre',
                'contact_ids'     => ['contact-out'],
                'message_channel' => 'sms',
            ],
            'status'               => 'PROPOSED',
        ]);

        Http::fake([
            'https://collaborator.test/*' => Http::sequence()
                ->push(['data' => ['opt_outs' => [['contact_id' => 'contact-out']]]]),
        ]);

        $this->actingAs($user)->postJson("/ai-commands/{$command->id}/confirm")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Création de la campagne impossible.']);

        // Le statut ne doit pas avoir changé
        $this->assertDatabaseHas('ai_commands', ['id' => $command->id, 'status' => 'PROPOSED']);
    }

    public function test_4_un_autre_utilisateur_ne_peut_pas_envoyer_la_campagne_dun_autre(): void
    {
        $owner    = User::factory()->create();
        $intruder = User::factory()->create();
        $this->linkAccount($owner);

        $command = AiCommand::create([
            'collaborator_user_id' => self::USER_ID,
            'intent'               => 'DRAFT_MESSAGE',
            'parameters'           => ['contact_ids' => ['contact-1']],
            'status'               => 'CONFIRMED',
            'campaign_id'          => 'campaign-private',
        ]);

        // L'intrus n'a pas de compte lié → pas de collaborator_user_id correspondant
        $this->actingAs($intruder)->postJson("/ai-commands/{$command->id}/send")
            ->assertNotFound();
    }

    /**
     * Reprise idempotente après échec partiel :
     *  1. Lors d'une première tentative, POST /groups a réussi mais POST /campaigns a échoué (500 serveur).
     *  2. Le group_id orphelin est sauvegardé dans les paramètres de l'AiCommand.
     *  3. La deuxième tentative de confirmation réutilise ce groupe existant (pas de nouveau POST /groups).
     *  → Évite la violation de contrainte unique « groups_user_id_name_unique » côté collaborateur.
     */
    public function test_4_reprise_idempotente_apres_echec_partiel_groupe_cree_campagne_echouee(): void
    {
        $user = User::factory()->create();
        $this->linkAccount($user);

        // L'AiCommand a déjà un group_id dans ses paramètres : le groupe a été créé
        // lors d'une première tentative, mais la campagne avait échoué.
        $command = AiCommand::create([
            'collaborator_user_id' => self::USER_ID,
            'intent'               => 'DRAFT_MESSAGE',
            'parameters'           => [
                'message'         => 'Offre fidélité',
                'contact_ids'     => ['contact-1', 'contact-2'],
                'message_channel' => 'sms',
                'group_id'        => 'group-already-created',  // ← résidu de la première tentative
            ],
            'status'               => 'PROPOSED',
            'estimated_recipients' => 2,
        ]);

        Http::fake([
            'https://collaborator.test/*' => Http::sequence()
                ->push(['data' => []])                           // GET /opt-outs
                // Pas de POST /groups cette fois : existing_group_id est fourni
                ->push(['data' => ['id' => 'campaign-retry']]), // POST /campaigns
        ]);

        $this->actingAs($user)->postJson("/ai-commands/{$command->id}/confirm")
            ->assertOk()
            ->assertJson(['status' => 'CONFIRMED', 'campaign_id' => 'campaign-retry']);

        // Vérifie qu'aucun nouveau groupe n'a été créé (seul POST vers /contacts ou /campaigns est acceptable)
        Http::assertNotSent(fn ($r): bool =>
            str_ends_with(rtrim($r->url(), '/'), '/groups')
            && strtoupper($r->method()) === 'POST'
        );

        // Vérifie que la campagne a bien été créée avec le groupe existant
        Http::assertSent(fn ($r): bool =>
            $r->url() === 'https://collaborator.test/api/v1/campaigns'
            && $r['group_id'] === 'group-already-created'
        );

        $this->assertDatabaseHas('ai_commands', [
            'id'          => $command->id,
            'status'      => 'CONFIRMED',
            'campaign_id' => 'campaign-retry',
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 5. CAMPAGNE POUR UN PUBLIC CIBLÉ
    // ──────────────────────────────────────────────────────────────────────────

    public function test_5_un_utilisateur_connecte_peut_generer_une_campagne_pour_un_public_cible(): void
    {
        $user = User::factory()->create();
        $this->linkAccount($user);
        $this->fakeLlmMessage('Un cadeau vous attend pour votre anniversaire !');

        Http::fake([
            'https://collaborator.test/*' => Http::response([
                'data' => [
                    ['id' => 'contact-10'],
                    ['id' => 'contact-11'],
                    ['id' => 'contact-12'],
                    ['id' => 'contact-13'],
                ],
            ]),
        ]);

        $response = $this->actingAs($user)->postJson('/chat', [
            'message' => 'Envoie une promotion de 20% à mes clients inactifs depuis 60 jours.',
        ])->assertOk();

        $this->assertStringContainsString('4 contacts', $response->json('answer'));
        $this->assertDatabaseHas('ai_commands', [
            'collaborator_user_id' => self::USER_ID,
            'status'               => 'PROPOSED',
            'estimated_recipients' => 4,
        ]);

        $command = AiCommand::latest('id')->first();
        $this->assertSame('clients inactifs depuis 60 jours', $command->parameters['audience']);
        $this->assertSame('20% de réduction', $command->parameters['offer']);
    }

    /** La proposition d'un public ciblé via l'API imbriquée réelle extrait bien les IDs */
    public function test_5_extraction_correcte_des_ids_depuis_le_format_api_reel(): void
    {
        $user = User::factory()->create();
        $this->linkAccount($user);
        $this->fakeLlmMessage('Offre spéciale fidélité !');

        // Format réel observé sur le serveur collaborateur (pagination imbriquée)
        Http::fake([
            'https://collaborator.test/*' => Http::response([
                'data' => [
                    'contacts' => [
                        'data' => [
                            ['id' => 'uuid-aaa'],
                            ['id' => 'uuid-bbb'],
                            ['id' => 'uuid-ccc'],
                        ],
                    ],
                ],
            ]),
        ]);

        $this->actingAs($user)->postJson('/chat', [
            'message' => 'Envoie une promotion de 25% à mes clients inactifs depuis 90 jours.',
        ])->assertOk();

        $command = AiCommand::latest('id')->first();
        $this->assertSame(3, $command->estimated_recipients);
        $this->assertEqualsCanonicalizing(['uuid-aaa', 'uuid-bbb', 'uuid-ccc'], $command->parameters['contact_ids']);
        $this->assertSame('clients inactifs depuis 90 jours', $command->parameters['audience']);
    }

    /** Flux complet : génération → confirmation → envoi pour un public ciblé */
    public function test_5_flux_complet_generation_confirmation_envoi_pour_un_public_cible(): void
    {
        $user = User::factory()->create();
        $this->linkAccount($user);
        $this->fakeLlmMessage('Réactivez votre compte avec -30% !');

        Http::fake([
            'https://collaborator.test/*' => Http::sequence()
                // Step 1 – /chat (search_contacts)
                ->push(['data' => [['id' => 'cid-x1'], ['id' => 'cid-x2']]])
                // Step 2 – /confirm
                ->push(['data' => []])                              // GET /opt-outs
                ->push(['data' => ['id' => 'group-full']])         // POST /groups
                ->push(['data' => []])                             // POST /groups/{id}/contacts
                ->push(['data' => ['id' => 'campaign-full']])      // POST /campaigns
                // Step 3 – /send
                ->push(['success' => true, 'data' => ['id' => 'campaign-full', 'status' => 'sent']]),
        ]);

        // 1. Génération
        $chat = $this->actingAs($user)->postJson('/chat', [
            'message' => 'Envoie une promotion de 30% à mes clients inactifs depuis 45 jours.',
        ])->assertOk();
        $this->assertStringContainsString('Voulez-vous confirmer cette campagne ?', $chat->json('answer'));

        $command = AiCommand::latest('id')->first();
        $this->assertSame('PROPOSED', $command->status);
        $this->assertSame(2, $command->estimated_recipients);
        $this->assertSame('clients inactifs depuis 45 jours', $command->parameters['audience']);

        // 2. Confirmation
        $this->actingAs($user)->postJson("/ai-commands/{$command->id}/confirm")
            ->assertOk()
            ->assertJson(['status' => 'CONFIRMED', 'campaign_id' => 'campaign-full']);

        // 3. Envoi
        $this->actingAs($user)->postJson("/ai-commands/{$command->id}/send")
            ->assertOk()
            ->assertJson(['status' => 'EXECUTED']);

        $this->assertDatabaseHas('ai_commands', [
            'id'          => $command->id,
            'status'      => 'EXECUTED',
            'campaign_id' => 'campaign-full',
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function makeCommand(User $user, string $channel): AiCommand
    {
        return AiCommand::create([
            'collaborator_user_id' => self::USER_ID,
            'intent'               => 'DRAFT_MESSAGE',
            'parameters'           => [
                'message'         => 'Découvrez notre offre.',
                'contact_ids'     => ['contact-1'],
                'message_channel' => $channel,
            ],
            'status'               => 'PROPOSED',
            'estimated_recipients' => 1,
        ]);
    }

    private function fakeLlmMessage(string $message): void
    {
        $this->app->instance(LLMProvider::class, new class($message) implements LLMProvider {
            public function __construct(private readonly string $message) {}
            public function chat(array $messages): string
            {
                return $this->message;
            }
        });
    }

    private function linkAccount(User $user): void
    {
        config(['collaborator.base_url' => 'https://collaborator.test/api/v1']);
        LinkedAccount::create([
            'user_id'              => $user->id,
            'collaborator_user_id' => self::USER_ID,
            'collaborator_email'   => 'api@example.com',
            'access_token'         => 'access-token',
            'refresh_token'        => 'refresh-token',
            'expires_at'           => now()->addHour(),
        ]);
    }
}
