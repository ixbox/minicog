<?php

namespace MiniCog\Tests;

use Aws\Command;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use MiniCog\Handler;
use MiniCog\UserHandler\UserHandlerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class HandlerTest extends TestCase
{
    private LoggerInterface $logger;
    private UserHandlerInterface $userHandler;
    private Handler $handler;

    protected function setUp(): void
    {
        // ロガーのモックを作成
        $this->logger = $this->createMock(LoggerInterface::class);

        // UserHandlerのモックを作成
        $this->userHandler = $this->createMock(UserHandlerInterface::class);

        // テスト対象のHandlerを作成
        $this->handler = new Handler($this->logger, $this->userHandler);
    }

    public function testConstructor(): void
    {
        // コンストラクタでlogger->debugが呼ばれることを確認
        $this->logger->expects($this->once())
            ->method('debug')
            ->with('[MiniCog] Mock Handler Initialized');

        // 新しいインスタンスを作成して検証
        new Handler($this->logger, $this->userHandler);
    }

    public function testInvokeCallsCorrectMethod(): void
    {
        // initiateAuthメソッドを呼び出すコマンドを作成
        $command = $this->createMock(Command::class);
        $command->method('getName')->willReturn('InitiateAuth');
        $command->method('toArray')->willReturn([
            'AuthParameters' => [
                'USERNAME' => 'testuser',
                'PASSWORD' => 'testpassword'
            ]
        ]);

        $request = $this->createMock(Request::class);

        // ユーザーが存在することを設定
        $this->userHandler->method('getUser')
            ->with('testuser')
            ->willReturn([
                'username' => 'testuser',
                'password' => 'testpassword'
            ]);

        // パスワード検証が成功することを設定
        $this->userHandler->method('verifyPassword')
            ->with('testuser', 'testpassword')
            ->willReturn(true);

        // ロガーが呼ばれることを確認（コンストラクタ、__invoke、initiateAuth、認証成功の4回）
        $this->logger->expects($this->atLeast(3))
            ->method('debug');

        // メソッドを実行
        $result = ($this->handler)($command, $request);

        // 戻り値がPromiseInterfaceであることを確認
        $this->assertInstanceOf(PromiseInterface::class, $result);
    }

    public function testInvokeThrowsExceptionForUnimplementedMethod(): void
    {
        $command = $this->createMock(Command::class);
        $command->method('getName')->willReturn('UnimplementedMethod');

        $request = $this->createMock(Request::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Operation UnimplementedMethod is not implemented');

        ($this->handler)($command, $request);
    }

    public function testInitiateAuthSuccess(): void
    {
        // テストユーザーを設定
        $username = 'testuser';
        $password = 'testpassword';

        $this->userHandler->method('getUser')
            ->with($username)
            ->willReturn([
                'username' => $username,
                'password' => $password
            ]);

        // パスワード検証が成功することを設定
        $this->userHandler->method('verifyPassword')
            ->with($username, $password)
            ->willReturn(true);

        // ロガーが呼ばれることを確認
        $this->logger->expects($this->exactly(2))
            ->method('debug');

        // メソッドを実行
        $result = $this->handler->initiateAuth([
            'AuthParameters' => [
                'USERNAME' => $username,
                'PASSWORD' => $password
            ]
        ]);

        // 結果を検証
        $this->assertArrayHasKey('AuthenticationResult', $result);
        $this->assertArrayHasKey('AccessToken', $result['AuthenticationResult']);
        $this->assertArrayHasKey('RefreshToken', $result['AuthenticationResult']);
        $this->assertArrayHasKey('IdToken', $result['AuthenticationResult']);
        $this->assertEquals(3600, $result['AuthenticationResult']['ExpiresIn']);
        $this->assertEquals('Bearer', $result['AuthenticationResult']['TokenType']);
    }

    public function testInitiateAuthThrowsExceptionForMissingParameters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required parameters: USERNAME or PASSWORD');

        $this->handler->initiateAuth([
            'AuthParameters' => [
                // USERNAMEとPASSWORDが欠けている
            ]
        ]);
    }

    public function testInitiateAuthThrowsExceptionForNonExistentUser(): void
    {
        // ユーザーが存在しないことを設定
        $this->userHandler->method('getUser')
            ->with('nonexistent')
            ->willReturn(null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('User does not exist');

        $this->handler->initiateAuth([
            'AuthParameters' => [
                'USERNAME' => 'nonexistent',
                'PASSWORD' => 'anypassword'
            ]
        ]);
    }

    public function testInitiateAuthThrowsExceptionForIncorrectPassword(): void
    {
        // ユーザーは存在するが、パスワードが異なる
        $this->userHandler->method('getUser')
            ->with('testuser')
            ->willReturn([
                'username' => 'testuser',
                'password' => 'correctpassword'
            ]);

        // パスワード検証が失敗することを設定
        $this->userHandler->method('verifyPassword')
            ->with('testuser', 'wrongpassword')
            ->willReturn(false);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Incorrect username or password');

        $this->handler->initiateAuth([
            'AuthParameters' => [
                'USERNAME' => 'testuser',
                'PASSWORD' => 'wrongpassword'
            ]
        ]);
    }

    public function testSignUpSuccess(): void
    {
        $username = 'newuser';
        $password = 'newpassword';
        $userAttributes = [
            ['Name' => 'email', 'Value' => 'new@example.com'],
            ['Name' => 'name', 'Value' => 'New User']
        ];

        // createUserが呼ばれることを確認
        $this->userHandler->expects($this->once())
            ->method('createUser')
            ->with(
                $username,
                $password,
                [
                    'email' => 'new@example.com',
                    'name' => 'New User'
                ]
            )
            ->willReturn([
                'username' => $username,
                'sub' => 'user-123'
            ]);

        // メソッドを実行
        $result = $this->handler->signUp([
            'Username' => $username,
            'Password' => $password,
            'UserAttributes' => $userAttributes
        ]);

        // 結果を検証
        $this->assertFalse($result['UserConfirmed']);
        $this->assertEquals('user-123', $result['UserSub']);
    }

    public function testSignUpThrowsExceptionForMissingParameters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required parameters: Username or Password');

        $this->handler->signUp([
            // UsernameとPasswordが欠けている
        ]);
    }

    public function testConfirmSignUpSuccess(): void
    {
        $username = 'testuser';

        // ユーザーが存在することを設定
        $this->userHandler->method('getUser')
            ->with($username)
            ->willReturn([
                'username' => $username
            ]);

        // ユーザーステータスが更新されることを確認
        $this->userHandler->expects($this->once())
            ->method('updateUserStatus')
            ->with($username, 'CONFIRMED');

        // メソッドを実行
        $result = $this->handler->confirmSignUp([
            'Username' => $username,
            'ConfirmationCode' => '123456'
        ]);

        // 結果を検証（空の配列が返される）
        $this->assertEmpty($result);
    }

    public function testConfirmSignUpThrowsExceptionForMissingParameters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required parameters: Username or ConfirmationCode');

        $this->handler->confirmSignUp([
            // UsernameとConfirmationCodeが欠けている
        ]);
    }

    public function testConfirmSignUpThrowsExceptionForNonExistentUser(): void
    {
        // ユーザーが存在しないことを設定
        $this->userHandler->method('getUser')
            ->with('nonexistent')
            ->willReturn(null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('User does not exist');

        $this->handler->confirmSignUp([
            'Username' => 'nonexistent',
            'ConfirmationCode' => '123456'
        ]);
    }

    public function testGenerateToken(): void
    {
        $username = 'testuser';
        $type = 'access';

        // privateメソッドをテストするためにリフレクションを使用
        $reflectionClass = new \ReflectionClass(Handler::class);
        $method = $reflectionClass->getMethod('generateToken');

        // メソッドを実行
        $token = $method->invoke($this->handler, $username, $type);

        // トークンがbase64エンコードされていることを確認
        $this->assertTrue(base64_decode($token, true) !== false);

        // デコードしたトークンを検証
        $decodedToken = json_decode(base64_decode($token), true);
        $this->assertEquals($username, $decodedToken['sub']);
        $this->assertEquals($type, $decodedToken['type']);
        $this->assertArrayHasKey('exp', $decodedToken);
        $this->assertArrayHasKey('iat', $decodedToken);

        // 有効期限が正しく設定されていることを確認（3600秒後）
        $this->assertEquals(3600, $decodedToken['exp'] - $decodedToken['iat']);
    }

    public function testInitiateAuthWithRefreshToken(): void
    {
        $username = 'testuser';
        $password = 'testpassword';

        // まず通常の認証でトークンを取得
        $this->userHandler->method('getUser')
            ->with($username)
            ->willReturn([
                'username' => $username,
                'password' => $password
            ]);

        $this->userHandler->method('verifyPassword')
            ->with($username, $password)
            ->willReturn(true);

        $authResult = $this->handler->initiateAuth([
            'AuthFlow' => 'USER_PASSWORD_AUTH',
            'AuthParameters' => [
                'USERNAME' => $username,
                'PASSWORD' => $password
            ]
        ]);

        $refreshToken = $authResult['AuthenticationResult']['RefreshToken'];

        // リフレッシュトークンで認証
        $this->userHandler->method('getUser')
            ->with($username)
            ->willReturn([
                'username' => $username
            ]);

        $refreshResult = $this->handler->initiateAuth([
            'AuthFlow' => 'REFRESH_TOKEN_AUTH',
            'AuthParameters' => [
                'REFRESH_TOKEN' => $refreshToken
            ]
        ]);

        $this->assertArrayHasKey('AuthenticationResult', $refreshResult);
        $this->assertArrayHasKey('AccessToken', $refreshResult['AuthenticationResult']);
        $this->assertArrayHasKey('IdToken', $refreshResult['AuthenticationResult']);
        $this->assertArrayNotHasKey('RefreshToken', $refreshResult['AuthenticationResult']); // リフレッシュ時は新しいRefreshTokenは返さない
    }

    public function testInitiateAuthWithRefreshTokenThrowsExceptionForMissingToken(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required parameter: REFRESH_TOKEN');

        $this->handler->initiateAuth([
            'AuthFlow' => 'REFRESH_TOKEN_AUTH',
            'AuthParameters' => []
        ]);
    }

    public function testInitiateAuthWithRefreshTokenThrowsExceptionForInvalidToken(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid refresh token');

        $this->handler->initiateAuth([
            'AuthFlow' => 'REFRESH_TOKEN_AUTH',
            'AuthParameters' => [
                'REFRESH_TOKEN' => 'invalid-token'
            ]
        ]);
    }

    public function testGetUserSuccess(): void
    {
        $username = 'testuser';

        // アクセストークンを生成
        $reflectionClass = new \ReflectionClass(Handler::class);
        $method = $reflectionClass->getMethod('generateToken');
        $accessToken = $method->invoke($this->handler, $username, 'access');

        // ユーザー情報を設定
        $this->userHandler->method('getUser')
            ->with($username)
            ->willReturn([
                'username' => $username,
                'attributes' => [
                    'email' => 'test@example.com',
                    'name' => 'Test User'
                ],
                'userStatus' => 'CONFIRMED',
                'userCreateDate' => 1000000,
                'userLastModifiedDate' => 2000000,
                'enabled' => true
            ]);

        $result = $this->handler->getUser([
            'AccessToken' => $accessToken
        ]);

        $this->assertEquals($username, $result['Username']);
        $this->assertEquals('CONFIRMED', $result['UserStatus']);
        $this->assertTrue($result['Enabled']);
        $this->assertIsArray($result['UserAttributes']);

        // 属性を確認
        $attributes = $result['UserAttributes'];
        $emailAttr = array_filter($attributes, fn($attr) => $attr['Name'] === 'email');
        $this->assertNotEmpty($emailAttr);
        $this->assertEquals('test@example.com', reset($emailAttr)['Value']);
    }

    public function testGetUserThrowsExceptionForMissingToken(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required parameter: AccessToken');

        $this->handler->getUser([]);
    }

    public function testGetUserThrowsExceptionForInvalidToken(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid access token');

        $this->handler->getUser([
            'AccessToken' => 'invalid-token'
        ]);
    }

    public function testGetUserThrowsExceptionForExpiredToken(): void
    {
        // 有効期限切れのトークンを生成
        $expiredToken = base64_encode(json_encode([
            'sub' => 'testuser',
            'type' => 'access',
            'exp' => time() - 100, // 過去の時刻
            'iat' => time() - 3700
        ]));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Access token has expired');

        $this->handler->getUser([
            'AccessToken' => $expiredToken
        ]);
    }
}
