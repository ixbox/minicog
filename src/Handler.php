<?php

namespace MiniCog;

use Aws\Command;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use Psr\Log\LoggerInterface;

class Handler
{
    public function __construct(
        private LoggerInterface $logger,
    )
    {
        echo "Hello from MiniCog!";
    }

    public function __invoke(Command $cmd, Request $request): PromiseInterface
    {
        $name = $cmd->getName();
        $this->logger->debug('[MiniCog] Invoking Mock Handler', [$name]);

        $result = call_user_func([$this, lcfirst($name)], $cmd->toArray());

        return Create::promiseFor($result);
    }

}