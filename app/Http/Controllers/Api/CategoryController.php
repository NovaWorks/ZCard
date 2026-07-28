<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $tree = Category::whereNull('parent_id')
            ->where('status', true)
            ->orderBy('sort')
            ->with(['children' => fn ($q) => $q->where('status', true)->orderBy('sort')])
            ->get(['id', 'name', 'slug', 'parent_id']);

        return response()->json($tree);
    }
}
