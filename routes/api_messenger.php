<?php

use App\Http\Controllers\Api\MessengerController;
use Illuminate\Support\Facades\Route;

/*
 * Messenger (employees only). Load it INSIDE your auth:sanctum group in routes/api.php:
 *     require __DIR__ . '/api_messenger.php';
 */
Route::prefix('messenger')->controller(MessengerController::class)->group(function () {
    Route::get('summary', 'summary');
    Route::get('people', 'people');
    Route::post('pusher-auth', 'pusherAuth');
    Route::get('conversations', 'conversations');
    Route::post('direct', 'direct');
    Route::post('groups', 'createGroup');
    Route::get('conversations/{id}', 'show')->whereNumber('id');
    Route::put('conversations/{id}', 'rename')->whereNumber('id');
    Route::post('conversations/{id}/messages', 'send')->whereNumber('id');
    Route::post('conversations/{id}/read', 'read')->whereNumber('id');
    Route::post('conversations/{id}/typing', 'typing')->whereNumber('id');
    Route::post('conversations/{id}/members', 'addMembers')->whereNumber('id');
    Route::delete('conversations/{id}/members/{employee}', 'removeMember')->whereNumber(['id', 'employee']);
    Route::put('conversations/{id}/members/{employee}/admin', 'setAdmin')->whereNumber(['id', 'employee']);
    Route::get('conversations/{id}/shared', 'shared')->whereNumber('id');
    Route::post('conversations/{id}/pin', 'pin')->whereNumber('id');
    Route::post('messages/{id}/react', 'react')->whereNumber('id');
    Route::delete('messages/{id}', 'unsend')->whereNumber('id');
});