<?php

namespace App\Http\Controllers;

use App\AI\Tools\CreateCampaignTool;
use App\AI\Tools\SendCampaignTool;
use App\Models\AiCommand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Throwable;

class CampaignCommandController extends Controller
{
    public function confirm(Request $request, AiCommand $command, CreateCampaignTool $campaignTool): JsonResponse
    {
        $command = $this->ownedCommand($request, $command);

        if (!$command) {
            return response()->json(['message' => 'Proposition introuvable ou non autorisée.'], 404);
        }

        if ($command->status !== 'PROPOSED') {
            return response()->json(['message' => 'La proposition doit être PROPOSED.'], 409);
        }

        try {
            $parameters = $command->parameters;
            $campaign = $campaignTool->execute([
                'collaborator_user_id' => $command->collaborator_user_id,
                'contact_ids'          => $parameters['contact_ids'] ?? [],
                'name'                 => 'Campagne IA #' . $command->id,
                'content'              => $parameters['message'] ?? '',
                'message_channel'      => $parameters['message_channel'] ?? 'sms',
                // Reprise idempotente : si le groupe a déjà été créé lors d'une tentative
                // précédente (groupe ok, campagne échouée), on le réutilise sans en créer un
                // nouveau, évitant ainsi la violation de contrainte unique côté collaborateur.
                'existing_group_id'    => $parameters['group_id'] ?? null,
            ]);

            $command->update([
                'campaign_id' => $campaign['campaign']['id'],
                'parameters' => array_merge($parameters, ['group_id' => $campaign['group_id']]),
            ]);
            $command->confirm();
        } catch (Throwable $exception) {
            return response()->json(['message' => 'Création de la campagne impossible.', 'error' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Proposition confirmée. La campagne est encore en brouillon.',
            'status' => $command->status,
            'campaign_id' => $command->campaign_id,
        ]);
    }

    public function cancel(Request $request, AiCommand $command): JsonResponse
    {
        $command = $this->ownedCommand($request, $command);

        if (!$command) {
            return response()->json(['message' => 'Proposition introuvable ou non autorisée.'], 404);
        }

        try {
            $command->cancel();
        } catch (LogicException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(['message' => 'Proposition annulée.', 'status' => $command->status]);
    }

    public function send(Request $request, AiCommand $command, SendCampaignTool $sendCampaign): JsonResponse
    {
        $command = $this->ownedCommand($request, $command);

        if (!$command) {
            return response()->json(['message' => 'Proposition introuvable ou non autorisée.'], 404);
        }

        if ($command->status !== 'CONFIRMED' || !$command->campaign_id) {
            return response()->json(['message' => 'La proposition doit être confirmée avant l’envoi.'], 409);
        }

        try {
            $sendCampaign->execute([
                'collaborator_user_id' => $command->collaborator_user_id,
                'campaign_id' => $command->campaign_id,
            ]);
            $command->update(['status' => 'EXECUTED', 'executed_at' => now()]);
        } catch (Throwable $exception) {
            return response()->json(['message' => 'Envoi impossible.', 'error' => $exception->getMessage()], 422);
        }

        return response()->json(['message' => 'Campagne envoyée.', 'status' => $command->status]);
    }

    private function ownedCommand(Request $request, AiCommand $command): ?AiCommand
    {
        return AiCommand::query()
            ->whereKey($command->getKey())
            ->where('collaborator_user_id', $request->user()->linkedAccount?->collaborator_user_id)
            ->first();
    }
}
