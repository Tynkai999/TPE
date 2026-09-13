<?php

namespace App\Console\Commands;

use App\AI\Exceptions\LLMUnavailableException;
use App\AI\Exceptions\ScrapingException;
use App\AI\Exceptions\SecurityException;
use App\AI\Services\WebsiteScraperService;
use App\AI\Tools\AnalyzeWebsiteTool;
use Illuminate\Console\Command;

class TestSiteScrapeCommand extends Command
{
    protected $signature = 'agent:test-site 
                            {url : L\'URL du site web à analyser (ex: https://example.com)}
                            {--without-ai : Tester uniquement le scraping et l\'extraction sans appel au modèle d\'IA}';

    protected $description = 'Teste en direct le scraping sécurisé et l\'analyse marketing d\'un site web par nos agents IA';

    public function handle(WebsiteScraperService $scraper, AnalyzeWebsiteTool $analyzeTool): int
    {
        $url = (string) $this->argument('url');

        $this->info("================================================================");
        $this->info("       TEST EN DIRECT : SCRAPING & ANALYSE D'UN SITE WEB       ");
        $this->info("================================================================");
        $this->line("URL cible : <comment>{$url}</comment>");
        $this->line("");

        // -------------------------------------------------------------
        // 1. Ingestion Sécurisée & Extraction Déterministe
        // -------------------------------------------------------------
        $this->comment("1. Ingestion réseau & Extraction déterministe (WebsiteScraperService)...");
        $startTime = microtime(true);

        try {
            $scraped = $scraper->scrape($url);
        } catch (SecurityException $e) {
            $this->error("BLOCAGE SÉCURITÉ (Anti-SSRF) : {$e->getMessage()}");
            return self::FAILURE;
        } catch (ScrapingException $e) {
            $this->error("ERREUR DE SCRAPING : {$e->getMessage()}");
            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error("ERREUR TECHNIQUE : {$e->getMessage()}");
            return self::FAILURE;
        }

        $duration = round((microtime(true) - $startTime) * 1000, 2);
        $this->info("✓ Site téléchargé et épuré en {$duration} ms !");
        $this->line("");

        // Affichage des données extraites
        $this->table(
            ['Propriété', 'Valeur extraite'],
            [
                ['Titre (<title> / og:title)', $scraped['title'] ?: '<aucun>'],
                ['Description (meta / og)', $scraped['meta_description'] ?: '<aucune>'],
                ['Mots utiles analysables', $scraped['summary_stats']['word_count'] . ' mots'],
                ['JSON-LD (Schema.org)', $scraped['summary_stats']['has_json_ld'] ? 'OUI (' . implode(', ', $scraped['summary_stats']['schema_types']) . ')' : 'Non'],
                ['Titres H1', implode(' | ', $scraped['headings']['h1']) ?: '<aucun>'],
                ['Titres H2', implode(' | ', array_slice($scraped['headings']['h2'], 0, 4)) ?: '<aucun>'],
            ]
        );

        if (!empty($scraped['json_ld']['business'])) {
            $this->line("");
            $this->info("Données Schema.org officielles (100% déterministes / 0 hallucination) :");
            $this->line(json_encode($scraped['json_ld']['business'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        $this->line("");
        $this->comment("Aperçu du texte assaini transmis aux agents IA :");
        $this->line(str_repeat('-', 60));
        $preview = mb_substr($scraped['clean_text'], 0, 400);
        $this->line($preview . (mb_strlen($scraped['clean_text']) > 400 ? '...' : ''));
        $this->line(str_repeat('-', 60));
        $this->line("");

        // -------------------------------------------------------------
        // 2. Analyse Sémantique par l'Agent IA
        // -------------------------------------------------------------
        if ($this->option('without-ai')) {
            $this->info("Option --without-ai activée : analyse IA ignorée.");
            return self::SUCCESS;
        }

        $this->comment("2. Analyse marketing par l'Agent IA (AnalyzeWebsiteTool)...");

        try {
            $aiResult = $analyzeTool->execute(['url' => $url]);

            $this->info("✓ Analyse marketing terminée et enregistrée en base (Profil #{$aiResult['profile_id']}) !");
            $this->line("");

            $this->table(
                ['Élément analysé par l\'IA', 'Synthèse factuelle (zéro hallucination)'],
                [
                    ['Nom commercial', $aiResult['business_name']],
                    ['Secteur d\'activité', $aiResult['activity_sector'] ?: 'Général'],
                    ['Spécialités / Produits phares', implode("\n• ", array_merge([''], $aiResult['key_offerings']))],
                    ['Axes de campagne suggérés', implode("\n• ", array_merge([''], $aiResult['suggested_campaign_angles']))],
                    ['Tonalité de communication', $aiResult['brand_tone']],
                    ['Avantage concurrentiel (USP)', $aiResult['unique_selling_proposition'] ?? '—'],
                ]
            );

            // ------------------------------------------------------------------
            // 3. Génération de vraies campagnes professionnelles par l'agent IA
            // ------------------------------------------------------------------
            $this->line("");
            $this->comment("3. Génération de campagnes marketing professionnelles (GenerateMessageTool)...");

            $generateTool = app(\App\AI\Tools\GenerateMessageTool::class);
            $firstOffering = $aiResult['key_offerings'][0] ?? 'nos services';
            $businessName = $aiResult['business_name'];
            $brandTone = $aiResult['brand_tone'];
            $activitySector = $aiResult['activity_sector'] ?? null;

            $channels = ['sms', 'email', 'whatsapp'];
            foreach ($channels as $channel) {
                $this->line("");
                $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
                $this->info("📣  CAMPAGNE " . strtoupper($channel) . " — {$businessName}");
                $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");

                try {
                    $campaign = $generateTool->execute([
                        'business_name'      => $businessName,
                        'key_offering'       => $firstOffering,
                        'offer'              => "Mise en avant de {$firstOffering}",
                        'audience'           => 'tous vos clients',
                        'tone'               => $brandTone,
                        'channel'            => $channel,
                        'activity_sector'    => $activitySector,
                        'key_offerings_list' => $aiResult['key_offerings'],
                    ]);
                    $this->line("");
                    $this->line($campaign['message']);
                } catch (\Throwable $e) {
                    $this->warn("  Erreur de génération ({$channel}) : " . $e->getMessage());
                }
            }

        } catch (LLMUnavailableException $e) {
            $this->warn("Le moteur d'IA local (LM Studio) n'est pas démarré.");
            $this->line("Pour tester également l'analyse IA :");
            $this->line("1. Lancez LM Studio sur votre Mac.");
            $this->line("2. Démarrez le serveur local (port 1234).");
            $this->line("3. Relancez cette commande.");
        } catch (\Throwable $e) {
            $this->error("Erreur lors de l'analyse IA : " . $e->getMessage());
        }

        $this->line("");
        $this->info("================================================================");
        return self::SUCCESS;
    }
}

