<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
 * The only channel of the application: the personal one of an active employee. Signals
 * carry no order data; the recipients are chosen by the current rights when a signal is
 * sent, so a lost right stops new signals without a reconnect.
 */
Broadcast::channel('App.Models.User.{id}', function (User $user, $id) {
    return $user->active && (int) $user->id === (int) $id;
});
