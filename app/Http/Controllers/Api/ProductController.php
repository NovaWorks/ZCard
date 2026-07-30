<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\StorefrontConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $pageSize = (int) StorefrontConfig::get('page_size');
        $order = $request->input('order', StorefrontConfig::get('default_order'));

        $query = Product::where('status', true)
            ->with(['skus' => fn ($q) => $q->where('status', true)->orderBy('sort')])
            ->withCount(['cards as stock' => fn ($q) => $q->where('status', 'unused')]);

        if ($categoryId = $request->input('category')) {
            $query->where('category_id', $categoryId);
        }

        $query = match ($order) {
            'price_asc' => $query->orderBy('price'),
            'price_desc' => $query->orderByDesc('price'),
            'sort' => $query->orderBy('sort'),
            default => $query->latest(),
        };

        $products = $query->paginate($pageSize);
        $products->getCollection()->transform(fn ($p) => $this->transform($p));

        return response()->json($products);
    }

    public function show(string $slug): JsonResponse
    {
        $product = Product::where('slug', $slug)
            ->where('status', true)
            ->with(['skus' => fn ($q) => $q->where('status', true)->orderBy('sort')])
            ->withCount(['cards as stock' => fn ($q) => $q->where('status', 'unused')])
            ->firstOrFail();

        return response()->json($this->transform($product, true));
    }

    public function featured(Request $request): JsonResponse
    {
        $count = (int) ($request->input('limit', StorefrontConfig::get('featured_count')));
        $products = Product::where('status', true)->where('is_featured', true)
            ->latest()->limit($count)
            ->withCount(['cards as stock' => fn ($q) => $q->where('status', 'unused')])
            ->get()->map(fn ($p) => $this->transform($p));

        return response()->json($products);
    }

    /** 统一输出格式:金额分,加 sales/stock */
    private function transform(Product $p, bool $detail = false): array
    {
        $data = [
            'id' => $p->id,
            'name' => $p->name,
            'slug' => $p->slug,
            'cover' => $p->cover,
            'price' => (int) $p->price,
            'stock' => (int) $p->stock,
            'sales' => $p->displaySales(),
            'is_featured' => (bool) $p->is_featured,
        ];
        if ($detail) {
            $data = array_merge($data, [
                'description' => $p->description,
                'images' => $p->images ?? [],
                'category' => $p->category?->only(['id', 'name', 'slug']),
                'skus' => $p->skus->map(fn ($s) => [
                    'id' => $s->id, 'name' => $s->name,
                    'price' => (int) $s->price, 'stock' => (int) $p->stock,
                ]),
                'virtual_reviews' => $p->virtual_reviews,
                'min_order' => $p->min_order, 'max_order' => $p->max_order,
                'stock_type' => $p->stock_type, 'delivery_mode' => $p->delivery_mode,
                'control_config' => $p->control_config ?? [],
                'member_price' => $p->member_price,
                'contact_type' => $p->contact_type ?? 'email',
                'only_user' => (bool) $p->only_user,
                'send_email' => (bool) $p->send_email,
                'leave_message' => $p->leave_message,
                'purchase_limit' => $p->purchase_limit ?? 0,
            ]);
        }
        return $data;
    }
}
