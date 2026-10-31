<?php

namespace App\Http\Controllers\Orders;

use App\Http\Controllers\Controller;
use App\Models\Call;
use App\Services\CallCards;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CallCardController extends Controller
{
    /**
     * The incoming call card for the employee the call is addressed to, while he may open
     * the call's order. Anyone else gets 404 — whether the call exists is not disclosed.
     */
    public function show(Request $request, CallCards $cards, int $call): JsonResponse
    {
        $model = Call::query()->find($call);
        $card = $model === null ? null : $cards->card($model, $request->user());

        abort_if($card === null, 404);

        return response()->json($card);
    }
}
