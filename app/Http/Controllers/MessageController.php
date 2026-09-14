<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\JsonResponse;
use App\AI\Orchestrator\AgentOrchestrator;

class MessageController extends Controller
{
    /**
     * Handle incoming IA chat messages.
     *
     * Expected payload:
     *   {
     *     "text": "string"
     *   }
     */
    public function __invoke(Request $request, AgentOrchestrator $orchestrator): JsonResponse
    {
        $validated = $request->validate([
            'text' => 'required|string',
            'history' => 'nullable|array',
            'history.*.role' => 'required|string|in:user,assistant',
            'history.*.content' => 'required|string',
        ]);

        // The authenticated user is provided by the "auth:api" middleware.
        $user = Auth::user();
        $collaboratorUserId = $user->collaborator_user_id ?? $user->linkedAccount?->collaborator_user_id ?? null;

        if ($collaboratorUserId === null) {
            return response()->json([
                'success' => false,
                'message' => 'Collaborator user ID not found in token.',
            ], 400);
        }

        try {
            $history = $validated['history'] ?? [];
            $response = $orchestrator->handle($validated['text'], $collaboratorUserId, $history);

            // Attempt to extract a proposal ID like "#123" from the response.
            $proposalId = null;
            if (preg_match('/#(\d+)/', $response, $matches)) {
                $proposalId = (int) $matches[1];
            }

            return response()->json([
                'success' => true,
                'message' => 'Message processed.',
                'data' => [
                    'content' => $response,
                    'proposal_id' => $proposalId,
                ],
            ]);
        } catch (\App\AI\Exceptions\LLMUnavailableException | \Illuminate\Http\Client\ConnectionException $e) {
            \Illuminate\Support\Facades\Log::warning('LLM inaccessible dans MessageController : ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => "Le moteur d'IA local n'est pas joignable (LM Studio est éteint ou inaccessible sur le port 1234).",
            ], 503);
        } catch (\App\AI\Exceptions\SecurityException $e) {
            return response()->json([
                'success' => false,
                'message' => "Accès refusé pour des raisons de sécurité : " . $e->getMessage(),
            ], 422);
        } catch (\App\AI\Exceptions\ScrapingException $e) {
            return response()->json([
                'success' => false,
                'message' => "Impossible d'analyser le site web : " . $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Erreur MessageController : ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Une erreur technique inattendue est survenue.',
            ], 500);
        }
    }
}

