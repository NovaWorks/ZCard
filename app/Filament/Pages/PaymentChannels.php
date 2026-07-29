<?php

namespace App\Filament\Pages;

use App\Models\PaymentChannel;
use App\Support\PaymentService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\URL;

class PaymentChannels extends Page
{
    protected string $view = 'filament.pages.payment-channels';

    public array $channels = [];

    public ?array $configChannel = null;

    public array $configFields = [];

    public array $configData = [];

    public static function getNavigationLabel(): string
    {
        return '支付通道';
    }

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return '系统';
    }

    public static function getNavigationSort(): ?int
    {
        return 2;
    }

    public static function getNavigationIcon(): string | \BackedEnum | null
    {
        return 'heroicon-o-credit-card';
    }

    public function mount(): void
    {
        $this->loadChannels();
    }

    protected function loadChannels(): void
    {
        $service = app(PaymentService::class);

        $this->channels = $service->getAllChannels()->map(function (PaymentChannel $channel) {
            $info = $this->resolveChannelInfo($channel);

            return [
                'id' => $channel->id,
                'name' => $channel->name,
                'code' => $channel->code,
                'driver' => $channel->driver,
                'icon' => $info['icon'] ?? '💳',
                'description' => $info['description'] ?? null,
                'enabled' => (bool) $channel->enabled,
                'config' => $channel->config ?? [],
            ];
        })->values()->all();
    }

    protected function resolveChannelInfo(PaymentChannel $channel): array
    {
        $driverClass = $channel->driver;

        if (! class_exists($driverClass)) {
            return ['icon' => '💳', 'description' => null];
        }

        try {
            return (new $driverClass())->getInfo();
        } catch (\Throwable $e) {
            return ['icon' => '💳', 'description' => null];
        }
    }

    public function configure(int $channelId): void
    {
        $channel = PaymentChannel::find($channelId);

        if (! $channel) {
            Notification::make()->danger()->title('支付通道不存在')->send();

            return;
        }

        $driverClass = $channel->driver;

        if (! class_exists($driverClass)) {
            Notification::make()->danger()->title("Driver 不存在: {$driverClass}")->send();

            return;
        }

        try {
            $driver = new $driverClass();
            $this->configFields = $driver->getConfigFields();
        } catch (\Throwable $e) {
            $this->configFields = [];
        }

        $this->configData = $channel->config ?? [];
        $this->configChannel = [
            'id' => $channel->id,
            'name' => $channel->name,
            'code' => $channel->code,
            'callback_url' => $this->buildCallbackUrl($channel->code),
        ];

        $this->dispatch('open-modal', id: 'configureChannel');
    }

    public function saveConfig(): void
    {
        if (! $this->configChannel) {
            return;
        }

        $data = $this->validateConfigData();

        app(PaymentService::class)->saveChannelConfig($this->configChannel['id'], $data);

        $this->loadChannels();

        Notification::make()->success()->title('支付通道配置已保存')->send();

        $this->closeModal();
    }

    public function toggle(int $channelId): void
    {
        $channel = PaymentChannel::find($channelId);

        if (! $channel) {
            Notification::make()->danger()->title('支付通道不存在')->send();

            return;
        }

        $enabled = ! $channel->enabled;

        app(PaymentService::class)->toggleChannel($channelId, $enabled);

        $this->loadChannels();

        Notification::make()
            ->success()
            ->title($enabled ? '已启用支付通道' : '已停用支付通道')
            ->send();
    }

    public function closeModal(): void
    {
        $this->configChannel = null;
        $this->configFields = [];
        $this->configData = [];

        $this->dispatch('close-modal', id: 'configureChannel');
    }

    protected function validateConfigData(): array
    {
        $data = $this->configData;

        foreach ($this->configFields as $fieldKey => $field) {
            if (! empty($field['required']) && empty($data[$fieldKey])) {
                Notification::make()
                    ->danger()
                    ->title('请填写: ' . ($field['label'] ?? $fieldKey))
                    ->send();

                $this->halt();
            }
        }

        return $data;
    }

    protected function buildCallbackUrl(string $code): string
    {
        if (app('router')->has('api.payments.callback')) {
            return route('api.payments.callback', ['channel' => $code]);
        }

        return URL::to('/api/payments/callback/' . $code);
    }
}
