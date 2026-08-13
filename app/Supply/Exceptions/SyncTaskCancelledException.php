<?php

namespace App\Supply\Exceptions;

use RuntimeException;

/** 内部控制流异常：同步任务已收到取消请求。 */
class SyncTaskCancelledException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('同步任务已取消');
    }
}
