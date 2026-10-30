<?php

use App\Http\Controllers\Api\CallbackRequestController;
use App\Http\Controllers\Api\MangoEventController;
use Illuminate\Support\Facades\Route;

Route::post('v1/callback-requests', CallbackRequestController::class)->name('api.callback-requests.store');

Route::post('mango/{webhookKey}/events/{event}', MangoEventController::class)
    ->where('event', '[a-z]+(/[a-z]+)?')
    ->name('api.mango.events');
