<?php

namespace App\Support;

use App\Models\Card;
use App\Models\CardImport;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * 卡密导入引擎(spec §5.1)。
 * 与 UI 无关 —— Filament Action 和 API Controller 都调它(API-first)。
 */
class CardImportService
{
    const THRESHOLD_QUEUE = 5000; // 超过此数转队列

    /**
     * 导入入口:解析 + 决定同步/队列,返回 CardImport 批次。
     *
     * options 支持:
     * - format: 'single'|'multi'
     * - delimiter: string|null
     * - source: string
     * - note: string|null     每条卡密统一打备注
     * - card_type: string|null 每条卡密统一卡密类型(如月卡)
     */
    public function import(int $productId, int $operatorId, string $rawInput, array $options = []): CardImport
    {
        $cards = $this->parse($rawInput, $options['format'] ?? 'single', $options['delimiter'] ?? null);
        $total = count($cards);

        $import = CardImport::create([
            'product_id' => $productId,
            'operator_id' => $operatorId,
            'source' => $options['source'] ?? 'manual',
            'total' => $total,
            'success_count' => 0,
            'failed_count' => 0,
            'status' => 'running',
        ]);

        if ($total === 0) {
            $import->update(['status' => 'completed']);
            return $import;
        }

        if ($total <= self::THRESHOLD_QUEUE) {
            $this->processSync($import, $cards, $options);
        } else {
            $tmpFile = $this->storeTempInput($rawInput, $import->id);
            \App\Jobs\ImportCardsJob::dispatch($import->id, $tmpFile, $options);
        }

        return $import;
    }

    /**
     * 解析:按 format 拆成卡密明文数组。
     * single/multi 都是整行入库(去空行/空白)。
     */
    public function parse(string $rawInput, string $format = 'single', ?string $delimiter = null): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $rawInput);
        $cards = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $cards[] = $line;
        }
        return $cards;
    }

    /** 同步处理(≤5000):分块跑完 */
    public function processSync(CardImport $import, array $cards, array $options = []): void
    {
        foreach (array_chunk($cards, 1000) as $chunk) {
            $this->processChunk($import, $chunk, $options);
        }
        $import->update(['status' => 'completed']);
    }

    /** 处理一块(1000 条):按商品设置决定是否去重，然后加密并批量插入 */
    public function processChunk(CardImport $import, array $chunk, array $options = []): void
    {
        $productId = $import->product_id;
        $importId = $import->id;
        // dedup: 优先用 options 覆盖,否则用商品设置
        $dedup = array_key_exists('dedup', $options)
            ? (bool) $options['dedup']
            : (bool) Product::query()->whereKey($productId)->value('dedup');

        // 可选的统一字段(导入时给本批每条卡密打上)
        $note = $options['note'] ?? null;
        $cardType = $options['card_type'] ?? null;

        // 算所有 hash
        $hashes = [];
        foreach ($chunk as $i => $plain) {
            $hashes[$i] = CardCipher::hash($plain);
        }

        // 仅在商品启用去重时查询历史卡密；关闭时允许相同内容重复入库。
        $existing = collect();
        if ($dedup) {
            $existing = DB::table('cards')
                ->where('product_id', $productId)
                ->whereIn('content_hash', array_values($hashes))
                ->pluck('content_hash')
                ->flip();
        }

        $toInsert = [];
        $success = 0;
        $skipped = 0; // 去重跳过(内容已存在,正常行为,不算失败)
        $failed = 0;
        $errors = [];
        $seenInChunk = []; // 本块内已见的 hash(防块内重复)
        foreach ($chunk as $i => $plain) {
            $hash = $hashes[$i];
            if ($dedup && ($existing->has($hash) || isset($seenInChunk[$hash]))) {
                $skipped++;
                continue;
            }
            if ($dedup) {
                $seenInChunk[$hash] = true;
            }
            $toInsert[] = [
                'product_id' => $productId,
                'import_id' => $importId,
                'content' => CardCipher::encrypt($plain),
                'content_hash' => $hash,
                'dedup_hash' => $dedup ? $hash : null,
                'status' => Card::STATUS_UNUSED,
                'note' => $note,
                'card_type' => $cardType,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $success++;
        }

        // 唯一索引继续兜底并发导入；去重关闭时 dedup_hash=NULL，允许相同内容重复。
        if ($toInsert) {
            $inserted = DB::table('cards')->insertOrIgnore($toInsert);
            if ($inserted < $success) {
                $concurrentSkipped = $success - $inserted;
                $success = $inserted;
                $skipped += $concurrentSkipped;
            }
        }

        // 累加统计(去重跳过单独计,不算失败)
        $import->increment('success_count', $success);
        $import->increment('skipped_count', $skipped);
        $import->increment('failed_count', $failed);
        if ($errors) {
            $current = $import->error_log ? (array) $import->error_log : [];
            $import->update(['error_log' => array_merge($current, $errors)]);
        }
    }

    /** 撤销某批次未用卡密(只删 unused,返回删除数) */
    public function revokeImport(int $importId): int
    {
        $count = Card::where('import_id', $importId)
            ->where('status', Card::STATUS_UNUSED)
            ->count();

        Card::where('import_id', $importId)
            ->where('status', Card::STATUS_UNUSED)
            ->delete();

        CardImport::where('id', $importId)->update(['status' => 'revoked']);

        return $count;
    }

    /** 存原始输入到临时文件(队列 Job 用,避免序列化大数组) */
    private function storeTempInput(string $rawInput, int $importId): string
    {
        $path = "card-imports/import-{$importId}-" . time() . '.txt';
        Storage::disk('local')->put($path, $rawInput);
        return $path;
    }
}
