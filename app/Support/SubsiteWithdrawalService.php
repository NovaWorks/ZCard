<?php

namespace App\Support;

use App\Models\Merchant;
use App\Models\SubsiteLedgerEntry;
use App\Models\Withdrawal;
use Illuminate\Support\Facades\DB;

/**
 * 分站 FIFO 提现(spec §7.3):实时 SUM(available) 校验,FIFO 消费 available 条目。
 * 部分提现拆分行保留审计(参考 dujiao-next)。不信任缓存余额。
 */
class SubsiteWithdrawalService
{
    public static function request(int $merchantId, int $amountFen, string $method, string $account, string $accountName): Withdrawal
    {
        return DB::transaction(function () use ($merchantId, $amountFen, $method, $account, $accountName) {
            $merchant = Merchant::lockForUpdate()->findOrFail($merchantId);

            $availableSum = SubsiteLedgerEntry::where('merchant_id', $merchantId)
                ->where('status', 'available')->sum('amount');
            if ($amountFen > $availableSum) {
                throw new \RuntimeException('可提现金额不足');
            }

            $remaining = $amountFen;
            $lockedIds = [];
            $entries = SubsiteLedgerEntry::where('merchant_id', $merchantId)
                ->where('status', 'available')
                ->whereNull('withdraw_request_id')
                ->orderBy('available_at')->orderBy('id')
                ->lockForUpdate()->get();

            foreach ($entries as $entry) {
                if ($remaining <= 0) {
                    break;
                }
                if ($entry->amount <= $remaining) {
                    $entry->update(['status' => 'locked']);
                    $lockedIds[] = $entry->id;
                    $remaining -= $entry->amount;
                } else {
                    $leftover = $entry->amount - $remaining;
                    SubsiteLedgerEntry::create([
                        'merchant_id' => $merchantId, 'order_id' => $entry->order_id,
                        'type' => $entry->type, 'amount' => $leftover, 'status' => 'available',
                        'available_at' => $entry->available_at, 'idempotency_key' => 'split:'.$entry->id.':'.uniqid(),
                    ]);
                    $entry->update(['amount' => $remaining, 'status' => 'locked']);
                    $lockedIds[] = $entry->id;
                    $remaining = 0;
                }
            }

            $withdrawal = Withdrawal::create([
                'user_id' => $merchant->user_id, 'amount' => $amountFen, 'actual_amount' => $amountFen,
                'fee' => 0, 'method' => $method, 'account' => $account, 'account_name' => $accountName,
                'status' => Withdrawal::STATUS_PENDING,
            ]);
            SubsiteLedgerEntry::whereIn('id', $lockedIds)->update(['withdraw_request_id' => $withdrawal->id]);

            return $withdrawal;
        });
    }

    public static function approve(int $withdrawalId): void
    {
        DB::transaction(function () use ($withdrawalId) {
            $w = Withdrawal::where('id', $withdrawalId)->lockForUpdate()->firstOrFail();
            if ($w->status !== Withdrawal::STATUS_PENDING) {
                throw new \RuntimeException('该记录无法操作');
            }
            $w->update(['status' => Withdrawal::STATUS_APPROVED]);
            SubsiteLedgerEntry::where('withdraw_request_id', $withdrawalId)->update(['status' => 'withdrawn']);
        });
    }

    public static function reject(int $withdrawalId, string $reason): void
    {
        DB::transaction(function () use ($withdrawalId, $reason) {
            $w = Withdrawal::where('id', $withdrawalId)->lockForUpdate()->firstOrFail();
            if ($w->status !== Withdrawal::STATUS_PENDING) {
                throw new \RuntimeException('该记录无法操作');
            }
            $w->update(['status' => Withdrawal::STATUS_REJECTED, 'reject_reason' => $reason]);
            SubsiteLedgerEntry::where('withdraw_request_id', $withdrawalId)
                ->update(['status' => 'available', 'withdraw_request_id' => null]);
        });
    }
}
