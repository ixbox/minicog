<?php

namespace MiniCog\Tests\UserHandler;

use MiniCog\UserHandler\MemoryUserHandler;
use PHPUnit\Framework\TestCase;

class MemoryUserHandlerTest extends TestCase
{
    private MemoryUserHandler $handler;
    private array $testUser = [
        'username' => 'testuser',
        'password' => 'dGVzdHBhc3N3b3Jk', // base64_encode('testpassword')
        'attributes' => ['email' => 'test@example.com'],
        'sub' => 'user-123',
        'userStatus' => 'CONFIRMED',
    ];

    protected function setUp(): void
    {
        $this->handler = new MemoryUserHandler([$this->testUser]);
    }

    public function testConstructorWithInitialUsers(): void
    {
        $user = $this->handler->getUser('testuser');
        $this->assertNotNull($user);
        $this->assertEquals('testuser', $user['username']);
        $this->assertEquals('dGVzdHBhc3N3b3Jk', $user['password']);
        $this->assertEquals('test@example.com', $user['attributes']['email']);
    }

    public function testGetUserReturnsNullForNonExistentUser(): void
    {
        $user = $this->handler->getUser('nonexistent');
        $this->assertNull($user);
    }

    public function testCreateUser(): void
    {
        $username = 'newuser';
        $password = 'newpassword';
        $attributes = ['email' => 'new@example.com'];

        $user = $this->handler->createUser($username, $password, $attributes);

        $this->assertEquals($username, $user['username']);
        $this->assertEquals(base64_encode($password), $user['password']);
        $this->assertEquals($attributes, $user['attributes']);
        $this->assertNotEmpty($user['sub']);
        $this->assertEquals('UNCONFIRMED', $user['userStatus']);
        $this->assertTrue($user['enabled']);

        // 取得して確認
        $retrievedUser = $this->handler->getUser($username);
        $this->assertNotNull($retrievedUser);
        $this->assertEquals($username, $retrievedUser['username']);
    }

    public function testCreateUserThrowsExceptionForExistingUser(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("User `testuser` already exists");

        $this->handler->createUser('testuser', 'password', []);
    }

    public function testUpdateUser(): void
    {
        $newAttributes = ['email' => 'updated@example.com', 'name' => 'Updated Name'];
        $user = $this->handler->updateUser('testuser', $newAttributes);

        $this->assertEquals('updated@example.com', $user['attributes']['email']);
        $this->assertEquals('Updated Name', $user['attributes']['name']);

        // 元の属性が保持されていることを確認
        $retrievedUser = $this->handler->getUser('testuser');
        $this->assertEquals('updated@example.com', $retrievedUser['attributes']['email']);
        $this->assertEquals('Updated Name', $retrievedUser['attributes']['name']);
    }

    public function testUpdateUserThrowsExceptionForNonExistentUser(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("User `nonexistent` does not exist");

        $this->handler->updateUser('nonexistent', ['email' => 'new@example.com']);
    }

    public function testDeleteUser(): void
    {
        $this->assertNotNull($this->handler->getUser('testuser'));

        $this->handler->deleteUser('testuser');

        $this->assertNull($this->handler->getUser('testuser'));
    }

    public function testUpdateUserStatus(): void
    {
        $user = $this->handler->updateUserStatus('testuser', 'ARCHIVED');

        $this->assertEquals('ARCHIVED', $user['userStatus']);

        // 取得して確認
        $retrievedUser = $this->handler->getUser('testuser');
        $this->assertEquals('ARCHIVED', $retrievedUser['userStatus']);
    }

    public function testUpdateUserStatusThrowsExceptionForNonExistentUser(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("User `nonexistent` does not exist");

        $this->handler->updateUserStatus('nonexistent', 'CONFIRMED');
    }

    public function testVerifyPassword(): void
    {
        // 正しいパスワード
        $this->assertTrue($this->handler->verifyPassword('testuser', 'testpassword'));

        // 誤ったパスワード
        $this->assertFalse($this->handler->verifyPassword('testuser', 'wrongpassword'));

        // 存在しないユーザー
        $this->assertFalse($this->handler->verifyPassword('nonexistent', 'anypassword'));
    }

    public function testUpdatePassword(): void
    {
        $this->handler->updatePassword('testuser', 'newpassword');

        // パスワードが更新されたことを確認
        $this->assertTrue($this->handler->verifyPassword('testuser', 'newpassword'));
        $this->assertFalse($this->handler->verifyPassword('testuser', 'testpassword'));

        // ユーザー情報を取得して確認
        $user = $this->handler->getUser('testuser');
        $this->assertEquals(base64_encode('newpassword'), $user['password']);
    }

    public function testUpdatePasswordThrowsExceptionForNonExistentUser(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("User `nonexistent` does not exist");

        $this->handler->updatePassword('nonexistent', 'newpassword');
    }
}
