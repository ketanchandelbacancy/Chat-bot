<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use App\Services\GeminiService;
use App\Services\VectorSearch;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

class ChatController extends Controller
{
    private const HISTORY_TURNS = 6;

    private const SYSTEM_PROMPT = <<<'PROMPT'
You are a helpful assistant that answers questions using ONLY the documents provided inside <documents> tags.
- If the answer is not contained in the documents, reply: "I don't know based on the uploaded files."
- Do not invent facts that are not in the documents.
- Keep answers clear and concise. Use bullet points or short paragraphs where helpful.
- At the end, mention the source file name(s) you used, e.g. (Source: handbook.pdf).
PROMPT;

    public function history(string $conversationId)
    {
        return ChatMessage::where('conversation_id', $conversationId)->oldest('id')->get();
    }

    public function ask(Request $request, GeminiService $gemini, VectorSearch $search)
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'max:2000'],
            'conversation_id' => ['nullable', 'uuid'],
        ]);

        $conversationId = $data['conversation_id'] ?? (string) Str::uuid();
        $question = trim($data['question']);

        $history = ChatMessage::where('conversation_id', $conversationId)
            ->latest('id')->take(self::HISTORY_TURNS)->get()->reverse()->values();

        try {
            // Include the previous user question so follow-ups ("what about the second one?") retrieve well.
            $lastUserQuestion = $history->where('role', 'user')->last()?->content;
            $searchText = $lastUserQuestion ? $lastUserQuestion."\n".$question : $question;

            $matches = $search->topK($gemini->embedQuery($searchText), 5);

            if (empty($matches)) {
                $answer = "I couldn't find anything relevant in the uploaded files. Try uploading a document or rephrasing your question.";
                $sources = [];
            } else {
                $context = collect($matches)->map(fn ($m, $i) => sprintf(
                    "<document index=\"%d\" source=\"%s\">\n%s\n</document>", $i + 1, e($m['source']), $m['content']
                ))->implode("\n\n");

                $turns = $history->map(fn (ChatMessage $m) => ['role' => $m->role, 'text' => $m->content])->all();
                $turns[] = [
                    'role' => 'user',
                    'text' => "<documents>\n{$context}\n</documents>\n\nQuestion: {$question}",
                ];

                $answer = $gemini->generate(self::SYSTEM_PROMPT, $turns);
                $sources = collect($matches)->pluck('source')->unique()->values()->all();
            }
        } catch (RequestException $e) {
            $status = $e->response->status();
            $message = $status === 429
                ? 'Gemini free-tier rate limit reached. Please wait a minute and try again.'
                : 'Gemini API error: '.($e->response->json('error.message') ?? $e->getMessage());

            return response()->json(['message' => $message], $status === 429 ? 429 : 502);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }

        ChatMessage::create(['conversation_id' => $conversationId, 'role' => 'user', 'content' => $question]);
        ChatMessage::create([
            'conversation_id' => $conversationId, 'role' => 'assistant', 'content' => $answer, 'sources' => $sources,
        ]);

        return [
            'conversation_id' => $conversationId,
            'answer' => $answer,
            'sources' => $sources,
        ];
    }
}
