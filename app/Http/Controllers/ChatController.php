<?php

namespace App\Http\Controllers;

use App\AI\Orchestrator\AgentOrchestrator;
use App\Models\AiConversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    public function store(Request $request, AgentOrchestrator $orchestrator): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:10000'],
            'conversation_id' => ['nullable', 'integer'],
        ]);

        $conversation = ($data['conversation_id'] ?? null)
            ? $request->user()->conversations()->findOrFail($data['conversation_id'])
            : $request->user()->conversations()->create([
                'title' => mb_substr($data['message'], 0, 80),
            ]);

        $conversation->messages()->create([
            'role' => 'user',
            'content' => $data['message'],
        ]);

        $history = $conversation->messages()
            ->latest('id')
            ->limit(20)
            ->get()
            ->reverse()
            ->map(fn ($message) => [
                'role' => $message->role,
                'content' => $message->content,
            ])
            ->values()
            ->all();

        $linkedAccount = $request->user()->linkedAccount;

        try {
            $answer = $orchestrator->handle(
                $data['message'],
                collaboratorUserId: $linkedAccount?->collaborator_user_id,
                conversation: array_slice($history, 0, -1),
            );
        } catch (\App\AI\Exceptions\LLMUnavailableException | \Illuminate\Http\Client\ConnectionException $e) {
            \Illuminate\Support\Facades\Log::warning('LLM inaccessible dans ChatController : ' . $e->getMessage());
            $answer = "Le moteur d'intelligence artificielle local n'est pas joignable pour le moment (vérifiez que LM Studio est bien démarré sur votre machine avec le serveur local actif sur le port 1234). Votre message a été conservé.";
        } catch (\App\AI\Exceptions\ScrapingException $e) {
            $answer = "Impossible d'accéder au site web indiqué ({$e->getMessage()}). Vérifiez que l'adresse est publique et accessible, ou décrivez-moi directement votre activité.";
        } catch (\App\AI\Exceptions\SecurityException $e) {
            $answer = "Pour des raisons de sécurité, cette adresse ne peut pas être analysée : {$e->getMessage()}";
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Erreur inattendue dans ChatController : ' . $e->getMessage());
            $answer = "Une anomalie technique temporaire est survenue. Veuillez réessayer dans quelques instants.";
        }

        $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $answer,
        ]);
        $conversation->touch();

        // Extraire l'ID de la proposition de campagne pour faciliter le frontend (ex: #30)
        $proposalId = null;
        if (preg_match('/#(\d+)/', $answer, $matches)) {
            $proposalId = (int) $matches[1];
        }

        return response()->json([
            'conversation_id' => $conversation->id,
            'answer' => $answer,
            'proposal_id' => $proposalId,
        ]);
    }

    /**
     * Liste les conversations de l'utilisateur.
     */
    public function index(Request $request): JsonResponse
    {
        $conversations = $request->user()->conversations()
            ->orderByDesc('updated_at')
            ->get(['id', 'title', 'updated_at', 'created_at']);

        return response()->json([
            'conversations' => $conversations,
        ]);
    }

    /**
     * Affiche les détails et les messages d'une conversation spécifique.
     */
    public function show(Request $request, $id): JsonResponse
    {
        $conversation = $request->user()->conversations()
            ->with(['messages' => function ($query) {
                $query->orderBy('created_at', 'asc');
            }])
            ->findOrFail($id);

        return response()->json([
            'conversation' => $conversation,
        ]);
    }
}
