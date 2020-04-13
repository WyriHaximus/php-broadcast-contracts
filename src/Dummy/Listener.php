<?php declare(strict_types=1);

namespace WyriHaximus\Broadcast\Dummy;

final class Listener implements \WyriHaximus\Broadcast\Marker\Listener
{
    public function handle(Event $event): void
    {
    }
}
