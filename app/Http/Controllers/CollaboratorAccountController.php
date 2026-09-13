<?php

namespace App\Http\Controllers;

use App\AI\Http\CollaboratorTokenManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class CollaboratorAccountController extends Controller
{
    public function store(Request $request, CollaboratorTokenManager $manager): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        try {
            $account = $manager->login($data['email'], $data['password']);
            $account->user_id = $request->user()->id;
            $account->save();
        } catch (Throwable $exception) {
            return response()->json([
                'message' => 'La liaison du compte collaborateur a échoué.',
                'error' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'Compte collaborateur lié.',
            'account' => [
                'collaborator_user_id' => $account->collaborator_user_id,
                'email' => $account->collaborator_email,
            ],
        ], 201);
    }
}
