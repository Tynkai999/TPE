<?php

namespace App\AI\Tools;

use App\AI\LLM\LLMProvider;
use InvalidArgumentException;

class GenerateSocialPostTool implements Tool
{
    public function __construct(
        private readonly LLMProvider $llm,
    ) {}

    public function name(): string
    {
        return 'generate_social_post';
    }

    public function description(): string
    {
        return 'Génère un post engageant pour les réseaux sociaux (Facebook, Instagram, LinkedIn, Twitter) incluant des hashtags et des suggestions d\'émojis ou d\'images.';
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
                        'platform' => [
                            'type' => 'string',
                            'enum' => ['facebook', 'instagram', 'linkedin', 'twitter'],
                            'description' => 'La plateforme sociale ciblée'
                        ],
                        'topic' => [
                            'type' => 'string',
                            'description' => 'Le sujet principal du post (ex: "nouvelle collection", "promotion de printemps")'
                        ],
                        'business_name' => [
                            'type' => 'string',
                            'description' => 'Le nom de l\'entreprise'
                        ],
                        'activity_sector' => [
                            'type' => 'string',
                            'description' => 'Le secteur d\'activité'
                        ],
                    ],
                    'required' => ['platform', 'topic'],
                ],
            ],
        ];
    }

    public function execute(array $arguments): array
    {
        $platform = $arguments['platform'] ?? null;
        $topic = $arguments['topic'] ?? null;

        if (!$platform || !$topic) {
            throw new InvalidArgumentException("La plateforme et le sujet sont obligatoires pour générer un post social.");
        }

        $businessName = $arguments['business_name'] ?? 'une entreprise';
        $sector = $arguments['activity_sector'] ?? '';

        $systemPrompt = <<<PROMPT
Tu es un Community Manager expert et créatif pour les TPE/PME.
Ton but est de rédiger un post parfait pour la plateforme demandée.
Règles par plateforme :
- Instagram : Visuel, engageant, beaucoup d'emojis, hashtags ciblés à la fin.
- LinkedIn : Professionnel, orienté valeur/networking, peu d'emojis, paragraphes aérés.
- Facebook : Convivial, axé sur la communauté, incitation aux commentaires.
- Twitter : Court, percutant, hashtags intégrés dans le texte.

Inclus toujours une "Suggestion de visuel" à la fin du post (ex: "[Visuel : Photo de l'équipe souriante]").
PROMPT;

        $userPrompt = "Entreprise : $businessName\n";
        if ($sector) {
            $userPrompt .= "Secteur : $sector\n";
        }
        $userPrompt .= "Plateforme : " . ucfirst($platform) . "\n";
        $userPrompt .= "Sujet : $topic\n\n";
        $userPrompt .= "Rédige le post maintenant.";

        $response = $this->llm->chat([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ]);

        return ['post' => trim($response['content'] ?? '')];
    }
}
