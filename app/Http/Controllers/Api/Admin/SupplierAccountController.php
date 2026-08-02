<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SupplierAccount;
use App\Models\SupplierLedgerEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 供货账号管理(spec §7.1) —— admin.role 保护
 */
class SupplierAccountController extends Controller
{
    /** GET /api/admin/supplier-accounts */
    public function index(Request $request): JsonResponse
    {
        $accounts = SupplierAccount::query()
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 20));

        return response()->json($accounts);
    }

    /** POST /api/admin/supplier-accounts (生成 key/secret,明文仅此一次返回) */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'contact' => 'nullable|string|max:200',
            'remark' => 'nullable|string|max:500',
        ]);

        $plainSecret = Str::random(64);
        $account = SupplierAccount::create([
            'name' => $data['name'],
            'api_key' => Str::random(32),
            'api_secret' => Crypt::encryptString($plainSecret),
            'balance' => 0,
            'status' => SupplierAccount::STATUS_ACTIVE,
            'contact' => $data['contact'] ?? null,
            'remark' => $data['remark'] ?? null,
        ]);

        return response()->json([
            'id' => $account->id,
            'name' => $account->name,
            'api_key' => $account->api_key,
            'api_secret' => $plainSecret,
            'balance' => 0,
            'warning' => __('messages.supply.secret_show_once_warning'),
        ], 201);
    }

    /** GET /api/admin/supplier-accounts/{id} (凭证脱敏) */
    public function show(SupplierAccount $supplierAccount): JsonResponse
    {
        $supplierAccount->api_secret = $this->maskSecret($supplierAccount);
        return response()->json($supplierAccount->makeVisible(['api_secret']));
    }

    /** PUT /api/admin/supplier-accounts/{id} */
    public function update(Request $request, SupplierAccount $supplierAccount): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:100',
            'status' => 'sometimes|in:active,disabled',
            'contact' => 'sometimes|nullable|string|max:200',
            'remark' => 'sometimes|nullable|string|max:500',
        ]);
        $supplierAccount->update($data);
        return response()->json($supplierAccount);
    }

    /** DELETE /api/admin/supplier-accounts/{id} */
    public function destroy(SupplierAccount $supplierAccount): JsonResponse
    {
        $supplierAccount->delete();
        return response()->json(null, 204);
    }

    /** POST /api/admin/supplier-accounts/{id}/reset-secret */
    public function resetSecret(SupplierAccount $supplierAccount): JsonResponse
    {
        $plainSecret = Str::random(64);
        $supplierAccount->update(['api_secret' => Crypt::encryptString($plainSecret)]);

        return response()->json([
            'id' => $supplierAccount->id,
            'api_key' => $supplierAccount->api_key,
            'api_secret' => $plainSecret,
            'warning' => __('messages.supply.secret_show_once_warning'),
        ]);
    }

    /** POST /api/admin/supplier-accounts/{id}/recharge */
    public function recharge(Request $request, SupplierAccount $supplierAccount): JsonResponse
    {
        $data = $request->validate([
            'amount' => 'required|integer|min:1',
            'remark' => 'nullable|string|max:200',
        ]);

        $key = 'recharge_' . $supplierAccount->id . '_' . time() . '_' . bin2hex(random_bytes(8));
        DB::transaction(function () use ($supplierAccount, $data, $key) {
            $locked = SupplierAccount::where('id', $supplierAccount->id)->lockForUpdate()->firstOrFail();
            $locked->increment('balance', $data['amount']);
            SupplierLedgerEntry::create([
                'supplier_account_id' => $locked->id,
                'type' => SupplierLedgerEntry::TYPE_RECHARGE,
                'amount' => $data['amount'],
                'balance_after' => $locked->fresh()->balance,
                'idempotency_key' => $key,
                'remark' => $data['remark'] ?? '管理员充值',
            ]);
        });

        return response()->json(['balance' => (int) $supplierAccount->fresh()->balance]);
    }

    /** POST /api/admin/supplier-accounts/{id}/adjust */
    public function adjust(Request $request, SupplierAccount $supplierAccount): JsonResponse
    {
        $data = $request->validate([
            'amount' => 'required|integer', // 可正可负
            'remark' => 'nullable|string|max:200',
        ]);

        $key = 'adjust_' . $supplierAccount->id . '_' . time() . '_' . bin2hex(random_bytes(8));
        DB::transaction(function () use ($supplierAccount, $data, $key) {
            $locked = SupplierAccount::where('id', $supplierAccount->id)->lockForUpdate()->firstOrFail();
            $locked->increment('balance', $data['amount']);
            SupplierLedgerEntry::create([
                'supplier_account_id' => $locked->id,
                'type' => SupplierLedgerEntry::TYPE_ADJUST,
                'amount' => $data['amount'],
                'balance_after' => $locked->fresh()->balance,
                'idempotency_key' => $key,
                'remark' => $data['remark'] ?? '管理员调整',
            ]);
        });

        return response()->json(['balance' => (int) $supplierAccount->fresh()->balance]);
    }

    /** GET /api/admin/supplier-accounts/{id}/ledger */
    public function ledger(Request $request, SupplierAccount $supplierAccount): JsonResponse
    {
        $entries = $supplierAccount->ledgerEntries()->orderByDesc('id')->paginate($request->integer('per_page', 20));
        return response()->json($entries);
    }

    private function maskSecret(SupplierAccount $account): string
    {
        try {
            $plain = Crypt::decryptString($account->getRawOriginal('api_secret'));
        } catch (\Throwable) {
            $plain = $account->getRawOriginal('api_secret');
        }
        return '••••••••' . substr($plain, -4);
    }
}
