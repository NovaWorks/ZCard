<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UploadController extends Controller
{
    public function image(Request $request): JsonResponse
    {
        $request->validate(['file' => 'required|image|max:5120']);
        $path = $request->file('file')->store('products', 'public');

        return response()->json(['path' => $path, 'url' => asset('storage/' . $path)]);
    }
}
