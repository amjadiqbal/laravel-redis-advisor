<?php

declare(strict_types=1);

namespace AmjadIqbal\RedisAdvisor;

enum Category: string
{
    case Queues = 'queues';
    case Keys = 'keys';
    case Server = 'server';
    case Sessions = 'sessions';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
