<?php

namespace App\Http\Controllers\Orders;

use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\OrdersListPreferencesRequest;
use App\Models\User;
use App\Models\UserPreference;
use App\Support\OrdersListPreferences;
use Illuminate\Http\Response;

class OrdersListPreferenceController extends Controller
{
    /**
     * Saves the whole view at once; the last save wins, as each one carries every setting.
     */
    public function update(OrdersListPreferencesRequest $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        UserPreference::query()->upsert(
            [[
                'user_id' => $user->id,
                'key' => UserPreference::ORDERS_LIST,
                'value' => json_encode(OrdersListPreferences::normalize($request->validated()), JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['user_id', 'key'],
            ['value', 'updated_at'],
        );

        return response()->noContent();
    }
}
