<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\SupplySource;
use App\Supply\SupplyManager;
use App\Supply\SupplySyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 商品同步队列任务(spec §5.1)
 * 全量(full)或增量(incremental)拉取上游商品并 upsert。
 */
class SyncSupplySourceProducts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $sourceId,
        public readonly string $mode = 'incremental',
    ) {}

    public function handle(SupplyManager $manager, SupplySyncService $sync): void
    {
        $source = SupplySource::find($this->sourceId);
        if (! $source || ! $source->isActive()) {
            return;
        }

        try {
            $driver = $manager->driver($source);
            $updatedAfter = $this->mode === 'incremental' ? $source->last_synced_at : null;
            $page = 1;
            $created = $updated = $hidden = 0;

            do {
                $result = $driver->listProducts($updatedAfter, $page);
                foreach ($result['items'] as $dto) {
                    $exists = Product::where('upstream_source_id', $source->id)
                        ->where('upstream_product_code', $dto->code)->exists();
                    $sync->upsertProduct($source, $dto);
                    if ($exists) {
                        $updated++;
                    } else {
                        $created++;
                    }
                    if (! $dto->isActive) {
                        $hidden++;
                    }
                }
                $page++;
            } while (! empty($result['has_more']));

            $source->update(['last_synced_at' => now(), 'last_error' => null]);
            Log::info("supply sync done source={$source->id} created={$created} updated={$updated} hidden={$hidden}");
        } catch (Throwable $e) {
            $source->update(['last_error' => $e->getMessage()]);
            Log::error("supply sync failed source={$source->id}: {$e->getMessage()}");
            throw $e;
        }
    }
}
