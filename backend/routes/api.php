<?php

use App\Http\Controllers\ChatController;
use App\Http\Controllers\DocumentController;
use Illuminate\Support\Facades\Route;

Route::get('/documents', [DocumentController::class, 'index']);
Route::post('/documents', [DocumentController::class, 'store']);
Route::post('/documents/{document}/reprocess', [DocumentController::class, 'reprocess']);
Route::delete('/documents/{document}', [DocumentController::class, 'destroy']);

Route::post('/chat', [ChatController::class, 'ask'])->middleware('throttle:20,1');
Route::get('/chat/{conversationId}', [ChatController::class, 'history']);
