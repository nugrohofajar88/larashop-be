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
            // Riwayat percakapan dari FE (asisten ini sendiri stateless) - dibatasi
            // 6 pesan (3 pasang tanya-jawab), diperkuat lagi di AiAssistantService.
            'history' => ['nullable', 'array', 'max:6'],
            'history.*.role' => ['required_with:history', 'string', 'in:user,assistant'],
            'history.*.content' => ['required_with:history', 'string', 'max:4000'],
        ]);

        $result = $assistant->ask($validated['question'], $validated['history'] ?? []);

        return response()->json([
            'data' => [
                'answer' => $result['answer'],
                'queries' => $result['queries'],
            ],
        ]);
    }
}
