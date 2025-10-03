<?php

declare(strict_types=1);

namespace Freyr\Messenger;

use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class FreyrMessengerBundle extends AbstractBundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
