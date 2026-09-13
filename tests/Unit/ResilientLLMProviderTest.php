<?php

namespace Tests\Unit;

use App\AI\Exceptions\LLMUnavailableException;
use App\AI\LLM\LLMProvider;
use App\AI\LLM\ResilientLLMProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResilientLLMProviderTest extends TestCase
{
    public function test_delegates_to_primary_when_working(): void
    {
        $mockPrimary = $this->createMock(LLMProvider::class);
        $mockPrimary->expects($this->once())
            ->method('chat')
            ->willReturn('Réponse normale du modèle local');

        $provider = new ResilientLLMProvider($mockPrimary, ['enabled' => false]);
        $result = $provider->chat([['role' => 'user', 'content' => 'Bonjour']]);

        $this->assertEquals('Réponse normale du modèle local', $result);
    }

    public function test_throws_llm_unavailable_exception_when_primary_offline_and_no_fallback(): void
    {
        $mockPrimary = $this->createMock(LLMProvider::class);
        $mockPrimary->expects($this->once())
            ->method('chat')
            ->willThrowException(new ConnectionException("cURL error 7: Failed to connect to host.docker.internal:1234"));

        $provider = new ResilientLLMProvider($mockPrimary, ['enabled' => false]);

        $this->expectException(LLMUnavailableException::class);
        $this->expectExceptionMessageMatches('/LM Studio/');
        $provider->chat([['role' => 'user', 'content' => 'Bonjour']]);
    }

    public function test_switches_to_fallback_cloud_when_primary_offline_and_fallback_configured(): void
    {
        $mockPrimary = $this->createMock(LLMProvider::class);
        $mockPrimary->expects($this->once())
            ->method('chat')
            ->willThrowException(new ConnectionException("cURL error 7"));

        Http::fake([
            'https://api.mistral.ai/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'Réponse du modèle Cloud de secours']],
                ],
            ]),
        ]);

        $provider = new ResilientLLMProvider($mockPrimary, [
            'enabled'  => true,
            'base_url' => 'https://api.mistral.ai/v1',
            'api_key'  => 'fake-api-key',
            'model'    => 'mistral-small-latest',
        ]);

        $result = $provider->chat([['role' => 'user', 'content' => 'Bonjour']]);

        $this->assertEquals('Réponse du modèle Cloud de secours', $result);
    }
}

