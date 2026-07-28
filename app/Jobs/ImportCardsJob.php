<?php

namespace App\Jobs;

use App\Models\CardImport;
use App\Support\CardImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * 大文件卡密导入队列 Job(spec §6)。
 * 从临时文件读原始输入,重新解析后分块处理。
 */
class ImportCardsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $importId,
        public string $tempFilePath,
        public array $options = [],
    ) {}

    public function handle(CardImportService $service): void
    {
        $import = CardImport::find($this->importId);
        if (! $import) {
            return;
        }

        try {
            $rawInput = Storage::disk('local')->get($this->tempFilePath);
            $cards = $service->parse($rawInput, $this->options['format'] ?? 'single', $this->options['delimiter'] ?? null);

            foreach (array_chunk($cards, 1000) as $chunk) {
                $service->processChunk($import, $chunk);
            }

            $import->update(['status' => 'completed']);
        } catch (\Throwable $e) {
            $import->update(['status' => 'failed']);
            report($e);
        } finally {
            Storage::disk('local')->delete($this->tempFilePath);
        }
    }
}
