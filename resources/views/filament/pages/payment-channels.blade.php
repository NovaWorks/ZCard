<x-filament-panels::page>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        @foreach ($channels as $channel)
            <div class="bg-white dark:bg-gray-900 rounded-card border border-gray-200 dark:border-gray-800 p-5 flex flex-col">
                <div class="flex items-start gap-3 mb-3">
                    <div class="text-3xl leading-none">{{ $channel['icon'] ?? '💳' }}</div>
                    <div class="flex-1 min-w-0">
                        <div class="font-bold text-ink truncate">{{ $channel['name'] }}</div>
                        <div class="text-xs text-ink-muted mt-0.5 uppercase">{{ $channel['code'] }}</div>
                    </div>
                    @if (!empty($channel['enabled']))
                        <span class="inline-flex items-center text-xs font-medium text-green-700 bg-green-100 dark:bg-green-900/40 dark:text-green-400 px-2 py-0.5 rounded-full">已启用</span>
                    @else
                        <span class="inline-flex items-center text-xs font-medium text-ink-soft bg-gray-100 dark:bg-gray-800 px-2 py-0.5 rounded-full">已停用</span>
                    @endif
                </div>

                @if (!empty($channel['description']))
                    <p class="text-sm text-ink-muted mb-4 flex-1">{{ $channel['description'] }}</p>
                @else
                    <div class="flex-1"></div>
                @endif

                <div class="flex items-center gap-2 mt-2">
                    <button type="button"
                            wire:click="configure({{ $channel['id'] }})"
                            class="inline-flex items-center text-sm font-medium text-primary hover:underline">
                        配置
                    </button>
                    <span class="text-gray-300">|</span>
                    @if (!empty($channel['enabled']))
                        <button type="button"
                                wire:click="toggle({{ $channel['id'] }})"
                                class="inline-flex items-center text-sm font-medium text-danger hover:underline">
                            停用
                        </button>
                    @else
                        <button type="button"
                                wire:click="toggle({{ $channel['id'] }})"
                                class="inline-flex items-center text-sm font-medium text-primary hover:underline">
                            启用
                        </button>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    {{-- 配置 Modal --}}
    <x-filament-panels::modal id="configureChannel" :visible="filled($configChannel)" width="2xl" wire:key="configure-channel-modal">
        <x-slot name="heading">
            {{ $configChannel['name'] ?? '' }} 配置
        </x-slot>

        <x-slot name="description">
            填写该支付通道所需的参数,保存后立即生效。
        </x-slot>

        <form wire:submit="saveConfig">
            @csrf
            <div class="space-y-4">
                @foreach ($configFields as $fieldKey => $field)
                    @php
                        $label = $field['label'] ?? $fieldKey;
                        $type = $field['type'] ?? 'text';
                        $value = $configData[$fieldKey] ?? ($field['default'] ?? '');
                        $required = !empty($field['required']);
                    @endphp

                    <div>
                        <label class="block text-sm font-medium text-ink mb-1">
                            {{ $label }}
                            @if ($required)<span class="text-danger">*</span>@endif
                        </label>

                        @if ($type === 'textarea')
                            <textarea wire:model="configData.{{ $fieldKey }}" rows="3"
                                class="block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 shadow-sm focus:border-primary focus:ring-primary text-sm"
                                @if ($required) required @endif></textarea>
                        @elseif ($type === 'select')
                            <select wire:model="configData.{{ $fieldKey }}"
                                class="block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 shadow-sm focus:border-primary focus:ring-primary text-sm"
                                @if ($required) required @endif>
                                @foreach (($field['options'] ?? []) as $optKey => $optLabel)
                                    <option value="{{ $optKey }}">{{ $optLabel }}</option>
                                @endforeach
                            </select>
                        @else
                            <input type="{{ $type }}" wire:model="configData.{{ $fieldKey }}"
                                value="{{ $value }}"
                                class="block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 shadow-sm focus:border-primary focus:ring-primary text-sm"
                                @if ($required) required @endif />
                        @endif
                    </div>
                @endforeach

                {{-- 回调地址 --}}
                <div class="rounded-md bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700 p-3">
                    <div class="text-xs font-medium text-ink-muted mb-1">异步通知回调地址 (Callback URL)</div>
                    <code class="text-xs text-ink-soft break-all select-all">{{ $configChannel['callback_url'] ?? '' }}</code>
                </div>
            </div>
        </form>

        <x-slot name="footerActions">
            <x-filament-panels::button color="gray" wire:click="closeModal">
                {{ __('filament-panels::components/modal.actions.cancel.label') }}
            </x-filament-panels::button>
            <x-filament-panels::button type="submit" form="configureChannel" wire:target="saveConfig" wire:loading.attr="disabled">
                保存
            </x-filament-panels::button>
        </x-slot>
    </x-filament-panels::modal>
</x-filament-panels::page>
