<?php

namespace App\OwnerConsole\Brief;

/** The contract-test double: records what it was asked to send, delivers nothing. Never bound outside tests. */
final class FakeOwnerChannel implements OwnerChannel
{
    /** @var list<string> */
    public array $sent = [];

    public ?OwnerSend $next = null;

    public function name(): string
    {
        return 'fake';
    }

    public function send(string $text): OwnerSend
    {
        if ($this->next !== null) {
            $result = $this->next;
            $this->next = null;

            return $result;
        }
        $this->sent[] = $text;

        return OwnerSend::sent('fake-'.count($this->sent));
    }
}
