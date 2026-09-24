<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin wrapper around the Gemini REST API (generativelanguage.googleapis.com).
 */
class GeminiService
{
    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/models';

    public const EMBEDDING_DIMENSIONS = 768;

    private function client(): PendingRequest
    {
        $key = config('services.gemini.key');

        if (empty($key)) {
            throw new RuntimeException('GEMINI_API_KEY is not set in backend/.env');
        }

        return Http::withHeaders(['x-goog-api-key' => $key])
            ->acceptJson()
            ->timeout(120)
            // Free tier is rate limited: retry 429 / 5xx with a growing delay.
            ->retry(4, fn (int $attempt) => $attempt * 5000, function ($exception) {
                return $exception instanceof RequestException
                    && in_array($exception->response->status(), [429, 500, 503], true);
            });
    }

    /**
     * Embed many texts in one request. Gemini accepts up to 100 texts per batch.
     *
     * @param  string[]  $texts
     * @return array<int, float[]>
     */
    public function embedBatch(array $texts, string $taskType = 'RETRIEVAL_DOCUMENT'): array
    {
        $model = config('services.gemini.embed_model');

        $response = $this->client()->post(self::BASE_URL."/{$model}:batchEmbedContents", [
            'requests' => array_map(fn (string $text) => [
                'model' => "models/{$model}",
                'content' => ['parts' => [['text' => $text]]],
                'taskType' => $taskType,
                'outputDimensionality' => self::EMBEDDING_DIMENSIONS,
            ], array_values($texts)),
        ])->throw()->json();

        return array_map(fn (array $e) => $e['values'], $response['embeddings'] ?? []);
    }

    /**
     * Embed a user question (uses the query-optimised task type).
     *
     * @return float[]
     */
    public function embedQuery(string $text): array
    {
        return $this->embedBatch([$text], 'RETRIEVAL_QUERY')[0];
    }

    /**
     * Generate an answer.
     *
     * @param  array<int, array{role: string, text: string}>  $contents  Conversation turns, oldest first.
     */
    public function generate(string $systemPrompt, array $contents): string
    {
        $model = config('services.gemini.chat_model');

        $response = $this->client()->post(self::BASE_URL."/{$model}:generateContent", [
            'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
            'contents' => array_map(fn (array $turn) => [
                // Gemini uses "model" for assistant turns.
                'role' => $turn['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $turn['text']]],
            ], $contents),
            'generationConfig' => ['temperature' => 0.2],
        ])->throw()->json();

        $parts = $response['candidates'][0]['content']['parts'] ?? [];
        $text = trim(implode('', array_column($parts, 'text')));

        if ($text === '') {
            $reason = $response['candidates'][0]['finishReason']
                ?? $response['promptFeedback']['blockReason']
                ?? 'unknown';

            return "Sorry, I couldn't generate an answer (reason: {$reason}).";
        }

        return $text;
    }
}
