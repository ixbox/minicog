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
        $logger = new NanoLogger((bool) getenv('MINICOG_DEBUG'));

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

    public function adminInitiateAuth(array $args): array
    {
        $this->logger->debug('[MiniCog] Mocking adminInitiateAuth', $args);

        $authFlow = $args['AuthFlow'] ?? 'ADMIN_NO_SRP_AUTH';

        if ($authFlow === 'REFRESH_TOKEN_AUTH') {
            return $this->handleRefreshTokenAuth($args);
        }

        if ($authFlow !== 'ADMIN_NO_SRP_AUTH' && $authFlow !== 'USER_PASSWORD_AUTH') {
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

        $this->logger->debug('[MiniCog] Admin authenticated user successfully', ['username' => $username]);

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
            'Attributes' => $userAttributes, // 互換性のため追加
            'UserStatus' => $user['userStatus'] ?? 'UNCONFIRMED',
            'UserCreateDate' => $user['userCreateDate'] ?? time(),
            'UserLastModifiedDate' => $user['userLastModifiedDate'] ?? time(),
            'Enabled' => $user['enabled'] ?? true,
        ];
    }

    public function listUsers(array $args): array
    {
        $this->logger->debug('[MiniCog] Mocking listUsers', $args);

        if (!isset($args['UserPoolId'])) {
            throw new \InvalidArgumentException('Missing required parameter: UserPoolId');
        }

        $allUsers = $this->userHandler->listUsers();
        $limit = $args['Limit'] ?? 60;
        $paginationToken = $args['PaginationToken'] ?? null;
        $filter = $args['Filter'] ?? null;

        // フィルタリング（簡易実装）
        $filteredUsers = $allUsers;
        if ($filter) {
            // Filter形式: "attribute = \"value\"" など
            // モック実装なので、簡易的に処理
            $filteredUsers = array_filter($allUsers, function ($user) use ($filter) {
                // 簡易的なフィルタリング（実際のCognitoはもっと複雑）
                if (stripos($filter, 'status') !== false) {
                    $status = $user['userStatus'] ?? 'UNCONFIRMED';
                    if (stripos($filter, $status) === false) {
                        return false;
                    }
                }
                return true;
            });
        }

        // ページネーション（簡易実装）
        $offset = 0;
        if ($paginationToken) {
            $offset = (int)base64_decode($paginationToken, true) ?: 0;
        }

        $users = array_slice($filteredUsers, $offset, $limit, true);
        $nextOffset = $offset + $limit;
        $hasMore = $nextOffset < count($filteredUsers);

        // Cognito形式のユーザーリストを構築
        $userList = [];
        foreach ($users as $user) {
            $userAttributes = [];
            foreach ($user['attributes'] ?? [] as $name => $value) {
                $userAttributes[] = [
                    'Name' => $name,
                    'Value' => (string)$value
                ];
            }

            $userList[] = [
                'Username' => $user['username'],
                'UserAttributes' => $userAttributes,
                'Attributes' => $userAttributes, // 互換性のため追加
                'UserStatus' => $user['userStatus'] ?? 'UNCONFIRMED',
                'UserCreateDate' => $user['userCreateDate'] ?? time(),
                'UserLastModifiedDate' => $user['userLastModifiedDate'] ?? time(),
                'Enabled' => $user['enabled'] ?? true,
            ];
        }

        $result = [
            'Users' => $userList,
        ];

        if ($hasMore) {
            $result['PaginationToken'] = base64_encode((string)$nextOffset);
        }

        return $result;
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

    public function describeUserPool(array $args): array
    {
        $this->logger->debug('[MiniCog] Mocking describeUserPool', $args);

        if (!isset($args['UserPoolId'])) {
            throw new \InvalidArgumentException('Missing required parameter: UserPoolId');
        }

        $userPoolId = $args['UserPoolId'];
        $now = time();

        // モック用のユーザープール情報を返す
        return [
            'UserPool' => [
                'Id' => $userPoolId,
                'Name' => 'MockUserPool',
                'Status' => 'Active',
                'CreationDate' => $now - 86400, // 1日前
                'LastModifiedDate' => $now,
                'AliasAttributes' => [],
                'UsernameAttributes' => [],
                'AutoVerifiedAttributes' => [],
                'SchemaAttributes' => [
                    [
                        'Name' => 'sub',
                        'AttributeDataType' => 'String',
                        'Required' => true,
                        'Mutable' => false,
                    ],
                    [
                        'Name' => 'email',
                        'AttributeDataType' => 'String',
                        'Required' => false,
                        'Mutable' => true,
                    ],
                ],
                'Policies' => [
                    'PasswordPolicy' => [
                        'MinimumLength' => 8,
                        'RequireUppercase' => false,
                        'RequireLowercase' => false,
                        'RequireNumbers' => false,
                        'RequireSymbols' => false,
                    ],
                ],
                'EstimatedNumberOfUsers' => count($this->userHandler->listUsers()),
            ],
        ];
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
