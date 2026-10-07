<?php

declare(strict_types=1);

namespace IndependentMall\Mail;

/** Safe to show to a customer: never attach provider exceptions or credentials. */
final class DeliveryException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('邮件暂时无法发送，请稍后重试或联系管理员。');
    }
}
