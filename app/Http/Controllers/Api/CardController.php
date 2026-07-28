<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Support\CardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CardController extends Controller
{
    public function stock(int $productId, CardService $service): JsonResponse
    {
        return response()->json([
            'product_id' => $productId,
            'stock' => $service->countStock($productId),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = Card::query();
        if ($productId = $request->input('product_id')) {
            $query->where('product_id', $productId);
        }
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        $cards = $query->orderByDesc('id')->paginate(20);
        // 不返回 content(加密密文),只返回元信息
        return response()->json($cards->through(fn ($c) => [
            'id' => $c->id,
            'product_id' => $c->product_id,
            'status' => $c->status,
            'created_at' => $c->created_at,
        ]));
    }

    public function export(int $productId, CardService $service): StreamedResponse
    {
        return response()->streamDownload(
            fn () => print($service->export($productId)),
            "cards-product-{$productId}-" . now()->format('Ymd_His') . '.txt',
            ['Content-Type' => 'text/plain; charset=UTF-8'],
        );
    }
}
