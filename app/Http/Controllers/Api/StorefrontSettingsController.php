<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\StorefrontConfig;
use Illuminate\Http\JsonResponse;

class StorefrontSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(StorefrontConfig::all());
    }
}
