<?php

namespace Tests\Unit;

use App\AI\LLM\LLMProvider;
use App\AI\Orchestrator\CommandParser;
use PHPUnit\Framework\TestCase;

class CommandParserTest extends TestCase
{
    public function test_parses_analyze_website_for_all_clients(): void
    {
        $mockLlm = $this->createMock(LLMProvider::class);
        $mockLlm->expects($this->once())
            ->method('chat')
            ->willReturn(json_encode([
                'intent'   => 'analyze_website',
                'url'      => 'https://boulangerie.fr',
                'audience' => 'all',
                'channel'  => 'sms',
            ]));

        $parser = new CommandParser($mockLlm);
        $result = $parser->parse('Voici mon site https://boulangerie.fr, propose une campagne pour tous mes clients');

        $this->assertNotNull($result);
        $this->assertEquals('analyze_website', $result['intent']);
        $this->assertEquals('https://boulangerie.fr', $result['url']);
        $this->assertEquals('all', $result['audience']);
        $this->assertNull($result['inactive_days']);
    }

    public function test_parses_analyze_website_for_inactive_clients(): void
    {
        $mockLlm = $this->createMock(LLMProvider::class);
        $mockLlm->expects($this->once())
            ->method('chat')
            ->willReturn(json_encode([
                'intent'        => 'analyze_website',
                'url'           => 'https://garage.com',
                'audience'      => 'inactive',
                'inactive_days' => 45,
                'channel'       => 'sms',
            ]));

        $parser = new CommandParser($mockLlm);
        $result = $parser->parse('Analyse https://garage.com et relance mes clients inactifs depuis 45 jours');

        $this->assertNotNull($result);
        $this->assertEquals('analyze_website', $result['intent']);
        $this->assertEquals('https://garage.com', $result['url']);
        $this->assertEquals('inactive', $result['audience']);
        $this->assertEquals(45, $result['inactive_days']);
    }

    public function test_extracts_url_even_if_llm_fails_json(): void
    {
        $mockLlm = $this->createMock(LLMProvider::class);
        $mockLlm->expects($this->once())
            ->method('chat')
            ->willReturn('Je vais regarder votre site tout de suite.');

        $parser = new CommandParser($mockLlm);
        $result = $parser->parse('Peux-tu analyser mon site https://moncommerce.fr pour mes clients ?');

        $this->assertNotNull($result);
        $this->assertEquals('analyze_website', $result['intent']);
        $this->assertEquals('https://moncommerce.fr', $result['url']);
        $this->assertEquals('all', $result['audience']);
    }
}

