<?php

namespace App\AI\Services;

use App\AI\Exceptions\ScrapingException;
use App\AI\Exceptions\SecurityException;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class WebsiteScraperService
{
    private const int TIMEOUT_SECONDS = 6;
    private const int CONNECT_TIMEOUT_SECONDS = 3;
    private const int MAX_BYTES = 1_500_000; // 1.5 Mo max
    private const int MAX_WORDS = 2_000;

    /**
     * Scrapes and analyzes a website by URL.
     *
     * @param string $url
     * @return array{
     *     url: string,
     *     title: string|null,
     *     meta_description: string|null,
     *     site_name: string|null,
     *     headings: array{h1: string[], h2: string[], h3: string[]},
     *     json_ld: array<string, mixed>,
     *     clean_text: string,
     *     summary_stats: array{word_count: int, has_json_ld: bool, schema_types: string[]}
     * }
     *
     * @throws InvalidArgumentException
     * @throws SecurityException
     * @throws ScrapingException
     */
    public function scrape(string $url): array
    {
        $sanitizedUrl = $this->validateAndSanitizeUrl($url);
        $this->verifyIpSafety($sanitizedUrl);

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->maxRedirects(3)
                ->withHeaders([
                    'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'fr-FR,fr;q=0.9,en-US;q=0.8,en;q=0.7',
                    'User-Agent'      => 'Mozilla/5.0 (compatible; TPE-Assistant-Bot/1.0; +https://tpe-assistant.fr)',
                ])
                ->get($sanitizedUrl);
        } catch (\Throwable $e) {
            throw new ScrapingException("Impossible de joindre le site web ({$sanitizedUrl}) : " . $e->getMessage(), 0, $e);
        }

        if (!$response->successful()) {
            throw new ScrapingException("Le site a retourné une erreur HTTP {$response->status()} ({$sanitizedUrl}).");
        }

        $body = $response->body();

        if (strlen($body) > self::MAX_BYTES) {
            $body = substr($body, 0, self::MAX_BYTES);
        }

        return $this->parseHtml($body, $sanitizedUrl);
    }

    /**
     * Parses and extracts structured content from raw HTML.
     *
     * @param string $html
     * @param string $url
     * @return array<string, mixed>
     */
    public function parseHtml(string $html, string $url = ''): array
    {
        if (trim($html) === '') {
            throw new ScrapingException("Le contenu du site est vide.");
        }

        // 1. Extraction déterministe du JSON-LD (Schema.org)
        $jsonLdData = $this->extractJsonLd($html);

        // 2. Préparation du DOMDocument
        $dom = new DOMDocument();
        $previousLibxmlState = libxml_use_internal_errors(true);

        // Forcer l'encodage UTF-8 pour éviter les problèmes de caractères accentués
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previousLibxmlState);

        $xpath = new DOMXPath($dom);

        // 3. Extraction des balises Meta et OpenGraph
        $meta = $this->extractMeta($xpath);

        // 4. Nettoyage sécurisé du DOM (Anti-SSRF & Anti-Prompt Injection)
        $this->purgeUnwantedElements($xpath);

        // 5. Extraction des titres
        $headings = [
            'h1' => $this->extractTagContents($xpath, '//h1'),
            'h2' => $this->extractTagContents($xpath, '//h2'),
            'h3' => $this->extractTagContents($xpath, '//h3'),
        ];

        // 6. Extraction du texte nettoyé
        $cleanText = $this->extractCleanText($xpath);

        $words = preg_split('/\s+/u', trim($cleanText), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $wordCount = count($words);

        return [
            'url'              => $url,
            'title'            => $meta['title'] ?? null,
            'meta_description' => $meta['description'] ?? null,
            'site_name'        => $meta['site_name'] ?? null,
            'headings'         => $headings,
            'json_ld'          => $jsonLdData,
            'clean_text'       => $cleanText,
            'summary_stats'    => [
                'word_count'   => $wordCount,
                'has_json_ld'  => !empty($jsonLdData['entities']),
                'schema_types' => $jsonLdData['types'] ?? [],
            ],
        ];
    }

    /**
     * Validates URL and ensures it uses HTTP or HTTPS.
     */
    public function validateAndSanitizeUrl(string $url): string
    {
        $url = trim($url);

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException("L'URL fournie n'est pas valide : {$url}");
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException("Seuls les protocoles http et https sont acceptés.");
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '') {
            throw new InvalidArgumentException("L'URL ne contient aucun nom de domaine ou hôte.");
        }

        return $url;
    }

    /**
     * Anti-SSRF check: Prevents accessing local or private network addresses.
     */
    public function verifyIpSafety(string $url): void
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        // Rejeter explicitement localhost et formats associés
        if (in_array(strtolower($host), ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true)) {
            throw new SecurityException("Accès aux hôtes locaux interdit (SSRF Protection).");
        }

        // Résolution DNS des IPs
        $ips = gethostbynamel($host);
        if ($ips === false || $ips === []) {
            throw new ScrapingException("Impossible de résoudre le nom de domaine : {$host}");
        }

        foreach ($ips as $ip) {
            if (!$this->isPublicIp($ip)) {
                throw new SecurityException("L'adresse IP résolue ({$ip}) pour l'hôte {$host} est une adresse privée ou réservée (SSRF Protection).");
            }
        }
    }

    /**
     * Check whether an IP is public and safe.
     */
    public function isPublicIp(string $ip): bool
    {
        // filter_var flags pour rejeter les adresses privées et réservées
        $isSafe = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        if ($isSafe === false) {
            return false;
        }

        // Vérifications de sous-réseaux sensibles additionnels
        $long = ip2long($ip);
        if ($long === false) {
            return true;
        }

        // 127.0.0.0/8 (Loopback)
        if (($long & 0xFF000000) === 0x7F000000) {
            return false;
        }

        // 10.0.0.0/8 (Private)
        if (($long & 0xFF000000) === 0x0A000000) {
            return false;
        }

        // 172.16.0.0/12 (Private)
        if (($long & 0xFFF00000) === 0xAC100000) {
            return false;
        }

        // 192.168.0.0/16 (Private)
        if (($long & 0xFFFF0000) === 0xC0A80000) {
            return false;
        }

        // 169.254.0.0/16 (Link-Local / AWS Metadata 169.254.169.254)
        if (($long & 0xFFFF0000) === 0xA9FE0000) {
            return false;
        }

        // 0.0.0.0/8
        if (($long & 0xFF000000) === 0x00000000) {
            return false;
        }

        return true;
    }

    /**
     * Extracts Schema.org JSON-LD scripts deterministically.
     *
     * @return array{entities: array<int, array<string, mixed>>, types: string[], business: array<string, mixed>|null}
     */
    private function extractJsonLd(string $html): array
    {
        $entities = [];
        $types = [];
        $businessInfo = null;

        if (preg_match_all('/<script\b[^>]*\btype=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches)) {
            foreach ($matches[1] as $scriptContent) {
                $trimmed = trim($scriptContent);
                if ($trimmed === '') {
                    continue;
                }

                $data = json_decode($trimmed, true);
                if (!is_array($data)) {
                    continue;
                }

                // Gérer le format @graph
                $items = isset($data['@graph']) && is_array($data['@graph']) ? $data['@graph'] : [$data];

                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    $type = $item['@type'] ?? null;
                    if (is_string($type)) {
                        $types[] = $type;
                    }

                    $entities[] = $item;

                    // Si c'est un profil d'entreprise ou de commerce
                    $businessTypes = [
                        'localbusiness', 'restaurant', 'store', 'bakery', 'beautySalon',
                        'hairSalon', 'medicalbusiness', 'dentist', 'automotivedealer',
                        'professionalservice', 'organization', 'corporation', 'website',
                    ];

                    if (is_string($type) && in_array(strtolower($type), $businessTypes, true)) {
                        $businessInfo = [
                            'type'        => $type,
                            'name'        => $item['name'] ?? null,
                            'description' => $item['description'] ?? null,
                            'telephone'   => $item['telephone'] ?? null,
                            'email'       => $item['email'] ?? null,
                            'price_range' => $item['priceRange'] ?? null,
                            'address'     => $item['address'] ?? null,
                            'opening_hours' => $item['openingHours'] ?? $item['openingHoursSpecification'] ?? null,
                            'serves_cuisine' => $item['servesCuisine'] ?? null,
                        ];
                    }
                }
            }
        }

        return [
            'entities' => $entities,
            'types'    => array_values(array_unique($types)),
            'business' => $businessInfo,
        ];
    }

    /**
     * Extracts title, meta description, and OpenGraph values.
     *
     * @return array<string, string>
     */
    private function extractMeta(DOMXPath $xpath): array
    {
        $meta = [];

        // Titre
        $ogTitle = $this->getFirstAttributeValue($xpath, '//meta[@property="og:title"]/@content');
        $htmlTitle = $this->getFirstNodeText($xpath, '//title');
        $meta['title'] = $ogTitle ?: $htmlTitle ?: '';

        // Description
        $ogDesc = $this->getFirstAttributeValue($xpath, '//meta[@property="og:description"]/@content');
        $metaDesc = $this->getFirstAttributeValue($xpath, '//meta[@name="description"]/@content');
        $meta['description'] = $ogDesc ?: $metaDesc ?: '';

        // Nom du site
        $siteName = $this->getFirstAttributeValue($xpath, '//meta[@property="og:site_name"]/@content');
        $meta['site_name'] = $siteName ?: '';

        return $meta;
    }

    /**
     * Removes non-content and malicious/hidden elements (Anti-Prompt Injection).
     */
    private function purgeUnwantedElements(DOMXPath $xpath): void
    {
        // 1. Suppression des balises inutiles
        $tagQueries = [
            '//script',
            '//style',
            '//noscript',
            '//svg',
            '//nav',
            '//footer',
            '//header',
            '//aside',
            '//iframe',
            '//form',
        ];

        foreach ($tagQueries as $query) {
            $nodes = $xpath->query($query);
            if ($nodes) {
                foreach ($nodes as $node) {
                    $node->parentNode?->removeChild($node);
                }
            }
        }

        // 2. Suppression des éléments cachés (Anti-Prompt Injection masqué)
        $hiddenQueries = [
            '//*[@hidden]',
            '//*[@aria-hidden="true"]',
            '//*[contains(@style, "display:none") or contains(@style, "display: none")]',
            '//*[contains(@style, "visibility:hidden") or contains(@style, "visibility: hidden")]',
            '//*[contains(@style, "opacity:0") or contains(@style, "opacity: 0")]',
        ];

        foreach ($hiddenQueries as $query) {
            $nodes = $xpath->query($query);
            if ($nodes) {
                foreach ($nodes as $node) {
                    $node->parentNode?->removeChild($node);
                }
            }
        }
    }

    /**
     * Extracts text from matching tags.
     *
     * @return string[]
     */
    private function extractTagContents(DOMXPath $xpath, string $query): array
    {
        $results = [];
        $nodes = $xpath->query($query);

        if ($nodes) {
            foreach ($nodes as $node) {
                $text = trim((string) $node->textContent);
                if ($text !== '' && !in_array($text, $results, true)) {
                    $results[] = $text;
                }
            }
        }

        return $results;
    }

    /**
     * Extracts cleaned textual body paragraphs and list items, capped to max words.
     */
    private function extractCleanText(DOMXPath $xpath): string
    {
        $blocks = [];
        $nodes = $xpath->query('//h1 | //h2 | //h3 | //p | //li');

        if ($nodes) {
            foreach ($nodes as $node) {
                $text = preg_replace('/\s+/u', ' ', (string) $node->textContent);
                $text = trim((string) $text);

                if (mb_strlen($text) >= 15) {
                    $blocks[] = $text;
                }
            }
        }

        $fullText = implode("\n", array_unique($blocks));

        // Troncature à 2 000 mots pour respecter les contextes LLM
        $words = preg_split('/\s+/u', $fullText, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($words) > self::MAX_WORDS) {
            $words = array_slice($words, 0, self::MAX_WORDS);
            $fullText = implode(' ', $words) . "\n[...Contenu tronqué...]";
        }

        return $fullText;
    }

    private function getFirstAttributeValue(DOMXPath $xpath, string $query): ?string
    {
        $nodes = $xpath->query($query);
        if ($nodes && $nodes->length > 0) {
            $val = trim((string) $nodes->item(0)?->nodeValue);
            return $val !== '' ? $val : null;
        }

        return null;
    }

    private function getFirstNodeText(DOMXPath $xpath, string $query): ?string
    {
        $nodes = $xpath->query($query);
        if ($nodes && $nodes->length > 0) {
            $val = trim((string) $nodes->item(0)?->textContent);
            return $val !== '' ? $val : null;
        }

        return null;
    }
}
