<?php

declare(strict_types=1);

namespace App\Tests\Import\Console;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class InMemoryMessageBus implements MessageBusInterface
{
    /**
     * @var list<object>
     */
    public array $dispatched = [];

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->dispatched[] = $message;

        return new Envelope($message);
    }
}
