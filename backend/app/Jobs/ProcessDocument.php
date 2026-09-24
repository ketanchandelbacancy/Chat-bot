<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\DocumentProcessor;
use App\Services\GeminiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Extracts text from an uploaded document, chunks it, embeds each chunk with Gemini
 * and stores the chunks + embeddings in MySQL.
 */
class ProcessDocument implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    private const BATCH_SIZE = 50;

    public function __construct(public Document $document) {}

    public function handle(DocumentProcessor $processor, GeminiService $gemini): void
    {
        $this->document->update(['status' => 'processing', 'error' => null]);

        $extension = pathinfo($this->document->name, PATHINFO_EXTENSION);
        $text = $processor->extractText(Storage::path($this->document->path), $extension);

        if (mb_strlen($text) < 20) {
            throw new RuntimeException('No readable text found. Scanned/image PDFs need OCR first.');
        }

        $chunks = $processor->chunk($text);
        $rows = [];

        foreach (array_chunk($chunks, self::BATCH_SIZE) as $batchIndex => $batch) {
            $vectors = $gemini->embedBatch($batch);

            foreach ($batch as $i => $content) {
                $rows[] = [
                    'document_id' => $this->document->id,
                    'chunk_index' => $batchIndex * self::BATCH_SIZE + $i,
                    'content' => $content,
                    'embedding' => json_encode($vectors[$i]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            // Stay under the free-tier requests-per-minute limit.
            if (count($chunks) > self::BATCH_SIZE) {
                sleep(2);
            }
        }

        DB::transaction(function () use ($rows) {
            $this->document->chunks()->delete();
            foreach (array_chunk($rows, 100) as $insert) {
                DB::table('document_chunks')->insert($insert);
            }
            $this->document->update(['status' => 'ready']);
        });
    }

    public function failed(?Throwable $exception): void
    {
        $this->document->update([
            'status' => 'failed',
            'error' => mb_substr($exception?->getMessage() ?? 'Unknown error', 0, 1000),
        ]);
    }
}
