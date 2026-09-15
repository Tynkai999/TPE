<?php

namespace App\AI\Tools;

use App\AI\LLM\LLMProvider;
use App\AI\Services\WebsiteScraperService;
use App\Models\WebsiteProfile;
use InvalidArgumentException;
use JsonException;

class AnalyzeWebsiteTool implements Tool
{
    public function __construct(
        private readonly WebsiteScraperService $scraper,
        private readonly LLMProvider $llm,
    ) {}

    public function name(): string
    {
        return 'analyze_website';
    }

    public function description(): string
    {
        return 'Extrait et analyse le contenu d\'un site web pour établir le profil commercial et marketing d\'une entreprise.';
    }

    public function getDefinition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => $this->description(),
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'url' => [
                            'type' => 'string',
                            'description' => 'L\'URL publique du site web à analyser (ex: https://example.com)'
                        ],
                    ],
                    'required' => ['url'],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     *   - url (string, obligatoire)
     *   - user_id (int|null, optionnel)
     *   - collaborator_user_id (string|null, optionnel)
     * @return array<string, mixed>
     */
    public function execute(array $arguments): array
    {
        $url = $arguments['url'] ?? null;
        if (!is_string($url) || trim($url) === '') {
            throw new InvalidArgumentException("L'URL du site web est obligatoire pour l'analyse.");
        }

        $userId = isset($arguments['user_id']) ? (int) $arguments['user_id'] : null;
        $collaboratorUserId = isset($arguments['collaborator_user_id']) && is_string($arguments['collaborator_user_id'])
            ? $arguments['collaborator_user_id']
            : null;

        // 1. Scraping sécurisé avec extraction déterministe
        $scraped = $this->scraper->scrape($url);

        // 2. Préparation du contexte factuel vérifié (Grounding)
        $headingsSummary = implode(' | ', array_slice(array_merge($scraped['headings']['h1'], $scraped['headings']['h2']), 0, 8));
        $jsonLdSummary = !empty($scraped['json_ld']['business']) ? json_encode($scraped['json_ld']['business'], JSON_UNESCAPED_UNICODE) : 'Non disponible';

        $promptContext = <<<CONTEXT
<site_content>
URL: {$scraped['url']}
Titre du site: {$scraped['title']}
Description: {$scraped['meta_description']}
Données structurées d'entreprise (Schema.org): {$jsonLdSummary}
Titres de sections: {$headingsSummary}

Contenu textuel extrait :
{$scraped['clean_text']}
</site_content>
CONTEXT;

        $systemPrompt = <<<PROMPT
Tu es un analyste marketing expert pour les TPE.
Analyse le contenu d'entreprise fourni pour synthétiser son profil commercial.

CONSIGNES DE VÉRITÉ ABSOLUE (ZÉRO HALLUCINATION) :
1. Fonde ton analyse STRICTEMENT sur les faits et informations mentionnés dans <site_content>.
2. Interdiction formelle d'inventer des remises commerciales, des tarifs, des garanties ou des spécialités absentes du texte.
3. Si un nom d'entreprise clair est présent dans les données Schema.org ou le titre, utilise-le en priorité.

Tu DOIS répondre EXCLUSIVEMENT sous la forme d'un objet JSON valide, sans balises markdown :
{
  "business_name": "Nom de l'entreprise ou enseigne",
  "activity_sector": "Secteur d'activité précis (ex: Boulangerie artisanale, Restaurant, Garage automobile)",
  "key_offerings": ["Produit ou service phare 1", "Produit ou service phare 2", "Produit ou service phare 3"],
  "suggested_campaign_angles": [
    "Angle 1 : [Type de campagne — ex: Lancement, Fidélisation, Notoriété] — [Hook marketing en une phrase percutante] — [Cible prioritaire]",
    "Angle 2 : [Type de campagne] — [Hook marketing] — [Cible prioritaire]",
    "Angle 3 : [Type de campagne] — [Hook marketing] — [Cible prioritaire]",
    "Angle 4 : [Type de campagne] — [Hook marketing] — [Cible prioritaire]"
  ],
  "tone": "Tonalité recommandée (ex: chaleureux et artisanal, dynamique et moderne, sobre et professionnel)",
  "unique_selling_proposition": "Ce qui distingue cette entreprise de ses concurrents, en une phrase basée sur le contenu du site"
}
PROMPT;

        $llmResult = $this->llm->chat([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $promptContext],
        ]);

        $llmResponse = is_array($llmResult) ? (string) ($llmResult['content'] ?? '') : (string) $llmResult;

        $cleanedResponse = trim(preg_replace('/^```(?:json)?|```$/m', '', $llmResponse) ?? $llmResponse);

        $parsed = [];
        try {
            $parsed = json_decode($cleanedResponse, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // Fallback déterministe si le LLM n'a pas renvoyé un JSON valide
            $businessName = $scraped['json_ld']['business']['name'] ?? $scraped['title'] ?? parse_url($url, PHP_URL_HOST);
            $parsed = [
                'business_name' => $businessName,
                'activity_sector' => $scraped['json_ld']['business']['type'] ?? 'Commerce / Services',
                'key_offerings' => array_slice($scraped['headings']['h2'], 0, 3),
                'suggested_campaign_angles' => [
                    'Notoriété — Faites découvrir vos services et votre savoir-faire — Nouveaux prospects',
                    'Fidélisation — Remerciez vos clients fidèles avec une attention exclusive — Clients existants',
                    'Réactivation — Rappelez-vous au bon souvenir de vos anciens clients — Clients inactifs',
                    'Saisonnière — Campagne événementielle adaptée à la période — Tous les clients',
                ],
                'tone' => 'professionnel et accueillant',
                'unique_selling_proposition' => null,
            ];
        }

        $businessName = !empty($parsed['business_name'])
            ? $parsed['business_name']
            : ($scraped['json_ld']['business']['name'] ?? $scraped['title'] ?? 'Votre entreprise');

        $activitySector = !empty($parsed['activity_sector'])
            ? $parsed['activity_sector']
            : ($scraped['json_ld']['business']['type'] ?? null);

        $keyOfferings = is_array($parsed['key_offerings'] ?? null)
            ? array_values(array_filter($parsed['key_offerings'], 'is_string'))
            : [];

        $suggestedAngles = is_array($parsed['suggested_campaign_angles'] ?? null)
            ? array_values(array_filter($parsed['suggested_campaign_angles'], 'is_string'))
            : [];

        $brandTone = is_string($parsed['tone'] ?? null) ? $parsed['tone'] : 'chaleureux et professionnel';
        $usp = is_string($parsed['unique_selling_proposition'] ?? null) ? $parsed['unique_selling_proposition'] : null;

        // 3. Persistance du profil dans la base de données
        $profile = WebsiteProfile::updateOrCreate(
            [
                'url' => $url,
                'collaborator_user_id' => $collaboratorUserId,
            ],
            [
                'user_id' => $userId,
                'business_name' => $businessName,
                'activity_sector' => $activitySector,
                'key_offerings' => $keyOfferings,
                'brand_tone' => $brandTone,
                'raw_metadata' => [
                    'meta' => [
                        'title' => $scraped['title'],
                        'description' => $scraped['meta_description'],
                        'site_name' => $scraped['site_name'],
                    ],
                    'json_ld' => $scraped['json_ld'],
                    'summary_stats' => $scraped['summary_stats'],
                ],
            ]
        );

        return [
            'profile_id'                => $profile->id,
            'url'                       => $url,
            'business_name'             => $businessName,
            'activity_sector'           => $activitySector,
            'key_offerings'             => $keyOfferings,
            'suggested_campaign_angles' => $suggestedAngles,
            'brand_tone'                => $brandTone,
            'unique_selling_proposition' => $usp,
            'summary_stats'             => $scraped['summary_stats'],
        ];
    }
}

