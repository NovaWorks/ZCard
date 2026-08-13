<?php

namespace App\Jobs;

use App\Support\AdminNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * 管理员登录告警(邮件/Telegram/企业微信)。
 * 异步执行,失败静默(仅记录日志,不影响登录)。
 */
class SendAdminAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public array $channels,
        public string $subject,
        public string $content,
    ) {}

    public function handle(): void
    {
        AdminNotifier::send($this->channels, $this->subject, $this->content);
    }
}
