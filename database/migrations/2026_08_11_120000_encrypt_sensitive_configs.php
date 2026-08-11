<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SETTING_KEYS = [
        'card_encryption_key',
        'mail_password',
        'sms_access_key',
        'sms_access_secret',
    ];

    public function up(): void
    {
        DB::table('settings')->whereIn('key', self::SETTING_KEYS)
            ->orderBy('id')->each(function ($row): void {
                $value = json_decode((string) $row->value, true);
                if (! is_string($value) || $value === '') {
                    return;
                }

                try {
                    Crypt::decryptString($value);

                    return; // 已加密
                } catch (Throwable) {
                    // 历史明文继续加密。
                }

                DB::table('settings')->where('id', $row->id)->update([
                    'value' => json_encode(Crypt::encryptString($value), JSON_UNESCAPED_SLASHES),
                ]);
            });

        DB::table('payment_channels')->orderBy('id')->each(function ($row): void {
            if ($row->config === null || $row->config === '') {
                return;
            }

            $decoded = json_decode((string) $row->config, true);
            if (is_string($decoded)) {
                try {
                    Crypt::decryptString($decoded);

                    return; // 已加密
                } catch (Throwable) {
                    return;
                }
            }
            if (! is_array($decoded)) {
                return;
            }

            $plain = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            DB::table('payment_channels')->where('id', $row->id)->update([
                'config' => json_encode(Crypt::encryptString($plain), JSON_UNESCAPED_SLASHES),
            ]);
        });
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', self::SETTING_KEYS)
            ->orderBy('id')->each(function ($row): void {
                $value = json_decode((string) $row->value, true);
                if (! is_string($value) || $value === '') {
                    return;
                }
                try {
                    $value = Crypt::decryptString($value);
                } catch (Throwable) {
                    return;
                }
                DB::table('settings')->where('id', $row->id)->update([
                    'value' => json_encode($value, JSON_UNESCAPED_SLASHES),
                ]);
            });

        DB::table('payment_channels')->orderBy('id')->each(function ($row): void {
            $cipher = json_decode((string) $row->config, true);
            if (! is_string($cipher)) {
                return;
            }
            try {
                $plain = Crypt::decryptString($cipher);
            } catch (Throwable) {
                return;
            }
            DB::table('payment_channels')->where('id', $row->id)->update(['config' => $plain]);
        });
    }
};
