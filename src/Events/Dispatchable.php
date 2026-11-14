<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Events;

trait Dispatchable
{
    public static function dispatch(mixed ...$arguments): mixed
    {
        return event(new static(...$arguments));
    }
}
