<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ChatController;

// 📚 LEARNING: Route::get() handles HTTP GET requests (e.g. loading the page in a browser).
// Route::post() handles HTTP POST requests (e.g. submitting a form or sending data via fetch()).
// The second argument maps to a Controller method: [ControllerClass::class, 'methodName']

Route::get('/', [ChatController::class, 'index']);

// 📚 LEARNING: We name this route 'chat.send' so we can generate its URL in Blade
// using route('chat.send') — this is cleaner than hardcoding '/chat/send'.
Route::post('/chat/send', [ChatController::class, 'send'])->name('chat.send');

// 📚 LEARNING — PHASE 2: New route to clear the current conversation and start fresh.
// We use POST (not GET) because this action CHANGES server state (deletes data).
// In REST conventions, data-mutating actions should never be GET requests —
// because GET requests can be triggered by link prefetching, browser history, etc.
Route::post('/chat/new', [ChatController::class, 'newConversation'])->name('chat.new');
