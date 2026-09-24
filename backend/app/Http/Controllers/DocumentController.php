<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessDocument;
use App\Models\Document;
use App\Services\DocumentProcessor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    public function index()
    {
        return Document::query()->withCount('chunks')->latest()->get();
    }

    public function store(Request $request)
    {
        $request->validate([
            'file' => [
                'required', 'file', 'max:20480', // 20 MB
                'extensions:'.implode(',', DocumentProcessor::SUPPORTED_EXTENSIONS),
            ],
        ]);

        $file = $request->file('file');

        $document = Document::create([
            'name' => $file->getClientOriginalName(),
            'path' => $file->store('documents'),
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
        ]);

        ProcessDocument::dispatch($document);

        return response()->json($document, 201);
    }

    public function reprocess(Document $document)
    {
        $document->update(['status' => 'pending', 'error' => null]);
        ProcessDocument::dispatch($document);

        return $document;
    }

    public function destroy(Document $document)
    {
        Storage::delete($document->path);
        $document->delete(); // chunks are removed by the FK cascade

        return response()->noContent();
    }
}
