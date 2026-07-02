<?php

declare(strict_types=1);

namespace Abitech\Payments\Events;

use Abitech\Payments\DTO\WebhookResult;

class WebhookReceived
{
    public function __construct(
        public readonly WebhookResult $result,
        public readonly array $rawPayload = []
    ) {}
}
