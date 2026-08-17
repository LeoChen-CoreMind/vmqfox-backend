<?php

namespace app\service;

final class QrcodeConflictChanged extends \RuntimeException
{
    public function __construct(private array $preview)
    {
        parent::__construct('二维码冲突状态已变化，请重新确认');
    }

    public function preview(): array
    {
        return $this->preview;
    }
}
