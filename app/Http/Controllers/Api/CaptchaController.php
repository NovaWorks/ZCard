<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\CaptchaService;
use App\Support\StorefrontConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 验证码控制器:返回验证码图片 URL + 各场景开关状态。
 */
class CaptchaController extends Controller
{
    /**
     * 返回指定场景的验证码图片 URL + 该场景是否启用。
     * GET /api/captcha/config?scene=register
     */
    public function config(Request $request): JsonResponse
    {
        $scene = $request->input('scene', 'default');
        $enabled = CaptchaService::isEnabled($scene);

        return response()->json([
            'enabled' => $enabled,
            'src' => $enabled ? CaptchaService::src($scene) : null,
        ]);
    }
}
