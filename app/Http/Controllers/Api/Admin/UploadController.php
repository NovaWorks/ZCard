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

        // 返回相对路径(浏览器按当前域名自动解析),不用 asset() 避免 APP_URL 不匹配生产域名
        return response()->json(['path' => $path, 'url' => '/storage/' . $path]);
    }
}
