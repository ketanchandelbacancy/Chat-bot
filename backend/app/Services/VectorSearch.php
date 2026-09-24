<?php

namespace App\Services;

use App\Models\DocumentChunk;

/**
 * Brute-force cosine similarity search over embeddings stored as JSON in MySQL.
 * Fine for a few thousand chunks; switch to a vector database beyond that.
 */
class VectorSearch
{
    /**
     * @param  float[]  $queryVector
     * @return array<int, array{score: float, content: string, source: string, document_id: int}>
     */
    public function topK(array $queryVector, int $k = 5, float $minScore = 0.3): array
    {
        $queryNorm = $this->norm($queryVector);
        $results = [];

        DocumentChunk::query()
            ->join('documents', 'documents.id', '=', 'document_chunks.document_id')
            ->where('documents.status', 'ready')
            ->select('document_chunks.id', 'document_chunks.document_id', 'document_chunks.content',
                'document_chunks.embedding', 'documents.name as source')
            ->orderBy('document_chunks.id')
            ->chunk(500, function ($rows) use (&$results, $queryVector, $queryNorm, $k) {
                foreach ($rows as $row) {
                    $results[] = [
                        'score' => $this->cosine($queryVector, $queryNorm, $row->embedding),
                        'content' => $row->content,
                        'source' => $row->source,
                        'document_id' => $row->document_id,
                    ];
                }

                // Keep memory bounded: only the best $k survive each batch.
                usort($results, fn ($a, $b) => $b['score'] <=> $a['score']);
                $results = array_slice($results, 0, $k);
            });

        return array_values(array_filter($results, fn ($r) => $r['score'] >= $minScore));
    }

    private function cosine(array $a, float $normA, array $b): float
    {
        $dot = 0.0;
        $normB = 0.0;

        foreach ($a as $i => $value) {
            $bi = $b[$i] ?? 0.0;
            $dot += $value * $bi;
            $normB += $bi * $bi;
        }

        return $dot / ($normA * sqrt($normB) + 1e-10);
    }

    private function norm(array $v): float
    {
        return sqrt(array_sum(array_map(fn ($x) => $x * $x, $v)));
    }
}
