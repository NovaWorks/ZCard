<?php

namespace App\Supply;

use App\Supply\Exceptions\UpstreamRequestException;
use Throwable;

/** 把任务异常转换为可安全持久化和展示的诊断。 */
final class SupplySyncError
{
    /** 把旧版本已写入数据库的裸运行时错误转换为可操作文案。 */
    public static function normalizeStoredMessage(?string $message): ?string
    {
        if ($message === null || $message === '') {
            return $message;
        }

        if (str_contains($message, 'Undefined property')
            && str_contains($message, 'UpstreamProduct')
            && (str_contains($message, 'productUrl') || str_contains($message, 'SproductUrl'))) {
            return '网站代码与队列进程版本不一致，请重启 queue:work / Supervisor 后重新同步';
        }

        return $message;
    }

    /** @return array{code:string, message:string, context:array, retryable:bool} */
    public static function diagnose(Throwable $e): array
    {
        if ($e instanceof UpstreamRequestException) {
            return [
                'code' => $e->errorCode,
                'message' => $e->getMessage(),
                'context' => $e->context,
                'retryable' => $e->retryable,
            ];
        }

        $message = $e->getMessage();
        if (self::normalizeStoredMessage($message) !== $message) {
            return [
                'code' => 'WORKER_VERSION_MISMATCH',
                'message' => (string) self::normalizeStoredMessage($message),
                'context' => [],
                'retryable' => false,
            ];
        }

        if (str_contains($e::class, 'TimeoutExceededException')) {
            return [
                'code' => 'TASK_TIMEOUT',
                'message' => '同步任务超过最大运行时间，已终止',
                'context' => [],
                'retryable' => true,
            ];
        }

        return [
            'code' => 'SYNC_INTERNAL_ERROR',
            'message' => mb_substr($message !== '' ? $message : '同步任务发生未知错误', 0, 1000),
            'context' => [],
            'retryable' => false,
        ];
    }
}
