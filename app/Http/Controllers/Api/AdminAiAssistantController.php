<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AiAssistantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAiAssistantController extends Controller
{
    public function ask(Request $request, AiAssistantService $assistant): JsonResponse
    {
        $validated = $request->validate([
            'question' => ['required', 'string', 'max:1000'],
        ]);

        $result = $assistant->ask($validated['question']);

        return response()->json([
            'data' => [
                'answer' => $result['answer'],
                'queries' => $result['queries'],
            ],
        ]);
    }
}
