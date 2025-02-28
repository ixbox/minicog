<?php

namespace MiniCog;

use Aws\Command;
use Aws\Result;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use MiniCog\UserHandler\UserHandlerInterface;
use Psr\Log\LoggerInterface;

class Handler
{
    private const MOCK_SESSION = 'mock-session';
    private const TOKEN_EXPIRY = 3600;

    public function __construct(
        private LoggerInterface $logger,
        private UserHandlerInterface $userHandler,
    ) {
        $this->logger->debug('[MiniCog] Mock Handler Initialized');
    }

    public function __invoke(Command $cmd, Request $request): PromiseInterface
    {
        $name = $cmd->getName();
        $this->logger->debug('[MiniCog] Invoking Mock Handler', ['name' => $name]);

        if (!method_exists($this, lcfirst($name))) {
            throw new \InvalidArgumentException("Operation $name is not implemented");
        }

        $result = call_user_func([$this, lcfirst($name)], $cmd->toArray());
        return Create::promiseFor(new Result($result));
    }

    public function initiateAuth(array $args): array
    {
        $this->logger->debug('[MiniCog] Mocking initiateAuth', $args);

        if (!isset($args['AuthParameters']['USERNAME'], $args['AuthParameters']['PASSWORD'])) {
            throw new \InvalidArgumentException('Missing required parameters: USERNAME or PASSWORD');
        }

        $username = $args['AuthParameters']['USERNAME'];
        $password = $args['AuthParameters']['PASSWORD'];

        $user = $this->userHandler->getUser($username);
        if (!$user) {
            $this->logger->debug('[MiniCog] User Not Found');
            throw new \InvalidArgumentException('User does not exist');
        }

        if ($user['password'] !== $password) {
            throw new \InvalidArgumentException('Incorrect username or password');
        }

        $this->logger->debug('[MiniCog] User authenticated successfully', ['username' => $username]);

        return [
            'AuthenticationResult' => [
                'AccessToken' => $this->generateToken($username, 'access'),
                'ExpiresIn' => self::TOKEN_EXPIRY,
                'TokenType' => 'Bearer',
                'RefreshToken' => $this->generateToken($username, 'refresh'),
                'IdToken' => $this->generateToken($username, 'id')
            ]
        ];
    }

    public function signUp(array $args): array
    {
        $this->logger->debug('[MiniCog] Mocking signUp', $args);

        if (!isset($args['Username'], $args['Password'])) {
            throw new \InvalidArgumentException('Missing required parameters: Username or Password');
        }

        $username = $args['Username'];
        $password = $args['Password'];
        $userAttributes = [];

        if (isset($args['UserAttributes']) && is_array($args['UserAttributes'])) {
            foreach ($args['UserAttributes'] as $attr) {
                $userAttributes[$attr['Name']] = $attr['Value'];
            }
        }

        $user = $this->userHandler->createUser($username, $password, $userAttributes);

        return [
            'UserConfirmed' => false,
            'UserSub' => $user['sub'] ?? uniqid('user-'),
        ];
    }

    public function confirmSignUp(array $args): array
    {
        $this->logger->debug('[MiniCog] Mocking confirmSignUp', $args);

        if (!isset($args['Username'], $args['ConfirmationCode'])) {
            throw new \InvalidArgumentException('Missing required parameters: Username or ConfirmationCode');
        }

        $username = $args['Username'];

        $user = $this->userHandler->getUser($username);
        if (!$user) {
            throw new \InvalidArgumentException('User does not exist');
        }

        // 本来はconfirmation codeの検証が必要ですが、モックなので常に成功とします
        return [];
    }

    private function generateToken(string $username, string $type): string
    {
        return base64_encode(json_encode([
            'sub' => $username,
            'type' => $type,
            'exp' => time() + self::TOKEN_EXPIRY,
            'iat' => time(),
        ]));
    }
}
