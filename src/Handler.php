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

    public static function handleCognitoApi(UserHandlerInterface $userHandler, array $vars = []): void
    {
        // 標準出力にログを出力するロガーの設定
        $logger = new class extends \Psr\Log\AbstractLogger {
            public function log($level, $message, array $context = []): void
            {
                error_log(sprintf("[%s] %s %s\n", $level, $message, json_encode($context)), 3, "php://stdout");
            }
        };

        $sendResponse = function (array|null $data = null, int $statusCode = 200): void {
            header('Content-Type: application/x-amz-json-1.1');
            http_response_code($statusCode);

            if ($data !== null) {
                echo json_encode($data);
            }
            exit;
        };

        $sendError = function (string $type, string $message, int $statusCode = 400) use ($sendResponse): void {
            $data = [
                '__type' => "com.amazonaws.cognito.identity.idp#$type",
                'message' => $message
            ];
            $sendResponse($data, $statusCode);
        };

        try {
            $factory = new \Http\Discovery\Psr17Factory();
            $request = $factory->createServerRequestFromGlobals();
            $method = $request->getMethod();
            $uri = $request->getUri()->getPath();

            // リクエストの基本検証
            if ($method !== 'POST' || $uri !== '/') {
                $sendError('NotFoundError', 'Endpoint not found', 404);
            }

            // x-amz-targetの検証
            $target = $request->getHeaderLine('x-amz-target');
            if (empty($target)) {
                $sendError('ValidationError', 'Missing required header: x-amz-target');
            }

            [$service, $name] = explode('.', $target, 2);
            if (strtolower($service) !== 'awscognitoidentityproviderservice') {
                $sendError('ValidationError', 'Invalid service name in x-amz-target');
            }

            // リクエストボディの解析
            $body = $request->getBody()->getContents();
            $args = json_decode($body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $sendError('ValidationError', 'Invalid JSON in request body');
            }

            // ハンドラーの初期化
            $handler = new self($logger, $userHandler);

            // コマンドの実行
            $result = $handler(new \Aws\Command($name, $args), $request)->wait();
            $sendResponse($result->toArray());
        } catch (\InvalidArgumentException $e) {
            $sendError('ValidationError', $e->getMessage());
        } catch (\Exception $e) {
            $logger->error('[MiniCog] Unexpected error', ['error' => $e->getMessage()]);
            $sendError('InternalErrorException', 'An internal error has occurred');
        }
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

        $authFlow = $args['AuthFlow'] ?? 'USER_PASSWORD_AUTH';

        if ($authFlow === 'REFRESH_TOKEN_AUTH') {
            return $this->handleRefreshTokenAuth($args);
        }

        if ($authFlow !== 'USER_PASSWORD_AUTH') {
            throw new \InvalidArgumentException("AuthFlow $authFlow is not supported");
        }

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

        if (!$this->userHandler->verifyPassword($username, $password)) {
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

    private function handleRefreshTokenAuth(array $args): array
    {
        if (!isset($args['AuthParameters']['REFRESH_TOKEN'])) {
            throw new \InvalidArgumentException('Missing required parameter: REFRESH_TOKEN');
        }

        $refreshToken = $args['AuthParameters']['REFRESH_TOKEN'];
        $tokenData = $this->decodeToken($refreshToken);

        if (!$tokenData || $tokenData['type'] !== 'refresh') {
            throw new \InvalidArgumentException('Invalid refresh token');
        }

        // トークンの有効期限をチェック
        if (isset($tokenData['exp']) && $tokenData['exp'] < time()) {
            throw new \InvalidArgumentException('Refresh token has expired');
        }

        $username = $tokenData['sub'];
        $user = $this->userHandler->getUser($username);
        if (!$user) {
            throw new \InvalidArgumentException('User does not exist');
        }

        $this->logger->debug('[MiniCog] Refresh token authenticated successfully', ['username' => $username]);

        return [
            'AuthenticationResult' => [
                'AccessToken' => $this->generateToken($username, 'access'),
                'ExpiresIn' => self::TOKEN_EXPIRY,
                'TokenType' => 'Bearer',
                'IdToken' => $this->generateToken($username, 'id')
            ]
        ];
    }

    public function getUser(array $args): array
    {
        $this->logger->debug('[MiniCog] Mocking getUser', $args);

        if (!isset($args['AccessToken'])) {
            throw new \InvalidArgumentException('Missing required parameter: AccessToken');
        }

        $accessToken = $args['AccessToken'];
        $tokenData = $this->decodeToken($accessToken);

        if (!$tokenData || $tokenData['type'] !== 'access') {
            throw new \InvalidArgumentException('Invalid access token');
        }

        // トークンの有効期限をチェック
        if (isset($tokenData['exp']) && $tokenData['exp'] < time()) {
            throw new \InvalidArgumentException('Access token has expired');
        }

        $username = $tokenData['sub'];
        $user = $this->userHandler->getUser($username);
        if (!$user) {
            throw new \InvalidArgumentException('User does not exist');
        }

        // Cognito形式のユーザー属性を構築
        $userAttributes = [];
        foreach ($user['attributes'] ?? [] as $name => $value) {
            $userAttributes[] = [
                'Name' => $name,
                'Value' => (string)$value
            ];
        }

        return [
            'Username' => $user['username'],
            'UserAttributes' => $userAttributes,
            'UserStatus' => $user['userStatus'] ?? 'UNCONFIRMED',
            'UserCreateDate' => $user['userCreateDate'] ?? time(),
            'UserLastModifiedDate' => $user['userLastModifiedDate'] ?? time(),
            'Enabled' => $user['enabled'] ?? true,
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

        // モックなので常に成功とし、ユーザーステータスをCONFIRMEDに更新
        $this->userHandler->updateUserStatus($username, 'CONFIRMED');

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

    private function decodeToken(string $token): ?array
    {
        $decoded = base64_decode($token, true);
        if ($decoded === false) {
            return null;
        }

        $data = json_decode($decoded, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return $data;
    }
}
