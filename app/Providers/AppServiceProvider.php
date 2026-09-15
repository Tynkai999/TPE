<?php

namespace App\Providers;

use App\AI\LLM\LLMProvider;
use App\AI\LLM\LMStudioProvider;
use App\AI\Tools\CreateCampaignTool;
use App\AI\Tools\GenerateMessageTool;
use App\AI\Tools\SearchContactsTool;
use App\AI\Tools\SendCampaignTool;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
        $this->app->bind(LLMProvider::class, function () {
            $primary = new LMStudioProvider(
                baseUrl: config('ai.lm_studio.base_url'),
                model: config('ai.lm_studio.model'),
                temperature: config('ai.lm_studio.temperature', 0.7),
                maxTokens: config('ai.lm_studio.max_tokens', 4096),
            );

            return new \App\AI\LLM\ResilientLLMProvider(
                primaryProvider: $primary,
                fallbackConfig: config('ai.fallback', []),
            );
        });

        $this->app->singleton(\App\AI\Tools\ToolRegistry::class);
        $this->app->singleton(SearchContactsTool::class);
        $this->app->singleton(GenerateMessageTool::class);
        $this->app->singleton(CreateCampaignTool::class);
        $this->app->singleton(SendCampaignTool::class);
        $this->app->singleton(\App\AI\Tools\AnalyzeWebsiteTool::class);
        $this->app->singleton(\App\AI\Tools\SaveCampaignProposalTool::class);
        $this->app->singleton(\App\AI\Tools\GenerateSocialPostTool::class);
        
        $this->app->afterResolving(\App\AI\Tools\ToolRegistry::class, function ($registry) {
            $registry->register($this->app->make(SearchContactsTool::class));
            $registry->register($this->app->make(GenerateMessageTool::class));
            $registry->register($this->app->make(CreateCampaignTool::class));
            $registry->register($this->app->make(SendCampaignTool::class));
            $registry->register($this->app->make(\App\AI\Tools\AnalyzeWebsiteTool::class));
            $registry->register($this->app->make(\App\AI\Tools\SaveCampaignProposalTool::class));
            $registry->register($this->app->make(\App\AI\Tools\GenerateSocialPostTool::class));
        });
        $this->app->singleton(\App\AI\Http\CollaboratorApiClient::class, fn () => new \App\AI\Http\CollaboratorApiClient(
            baseUrl: config('collaborator.base_url'),
        ));
        $this->app->singleton(\App\AI\Http\CollaboratorTokenManager::class);
        $this->app->singleton(\App\AI\Services\WebsiteScraperService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
