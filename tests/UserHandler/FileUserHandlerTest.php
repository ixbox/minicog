<?php

namespace MiniCog\Tests\UserHandler;

use MiniCog\UserHandler\FileUserHandler;
use PHPUnit\Framework\TestCase;

class FileUserHandlerTest extends TestCase
{
    private string $testFile;
    private FileUserHandler $handler;

    protected function setUp(): void
    {
        $this->testFile = sys_get_temp_dir() . '/minicog_test_' . uniqid() . '.json';
        $this->handler = new FileUserHandler($this->testFile);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->testFile)) {
            unlink($this->testFile);
        }
    }

    public function testCreateAndGetUser(): void
    {
        $user = $this->handler->createUser('testuser', 'testpassword', ['email' => 'test@example.com']);

        $this->assertEquals('testuser', $user['username']);
        $this->assertEquals('UNCONFIRMED', $user['userStatus']);
        $this->assertEquals(['email' => 'test@example.com'], $user['attributes']);

        $retrieved = $this->handler->getUser('testuser');
        $this->assertNotNull($retrieved);
        $this->assertEquals('testuser', $retrieved['username']);
    }

    public function testGetUserReturnsNullForNonExistentUser(): void
    {
        $user = $this->handler->getUser('nonexistent');
        $this->assertNull($user);
    }

    public function testCreateUserThrowsExceptionForExistingUser(): void
    {
        $this->handler->createUser('testuser', 'testpassword');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("User `testuser` already exists");

        $this->handler->createUser('testuser', 'anotherpassword');
    }

    public function testUpdateUser(): void
    {
        $this->handler->createUser('testuser', 'testpassword', ['email' => 'test@example.com']);

        $updated = $this->handler->updateUser('testuser', ['email' => 'updated@example.com', 'name' => 'Test']);

        $this->assertEquals('updated@example.com', $updated['attributes']['email']);
        $this->assertEquals('Test', $updated['attributes']['name']);
    }

    public function testUpdateUserThrowsExceptionForNonExistentUser(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("User `nonexistent` does not exist");

        $this->handler->updateUser('nonexistent', ['email' => 'test@example.com']);
    }

    public function testDeleteUser(): void
    {
        $this->handler->createUser('testuser', 'testpassword');
        $this->assertNotNull($this->handler->getUser('testuser'));

        $this->handler->deleteUser('testuser');
        $this->assertNull($this->handler->getUser('testuser'));
    }

    public function testUpdateUserStatus(): void
    {
        $this->handler->createUser('testuser', 'testpassword');

        $updated = $this->handler->updateUserStatus('testuser', 'CONFIRMED');

        $this->assertEquals('CONFIRMED', $updated['userStatus']);
    }

    public function testUpdateUserStatusThrowsExceptionForNonExistentUser(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("User `nonexistent` does not exist");

        $this->handler->updateUserStatus('nonexistent', 'CONFIRMED');
    }

    public function testVerifyPassword(): void
    {
        $this->handler->createUser('testuser', 'testpassword');

        $this->assertTrue($this->handler->verifyPassword('testuser', 'testpassword'));
        $this->assertFalse($this->handler->verifyPassword('testuser', 'wrongpassword'));
        $this->assertFalse($this->handler->verifyPassword('nonexistent', 'anypassword'));
    }

    public function testUpdatePassword(): void
    {
        $this->handler->createUser('testuser', 'testpassword');

        $this->handler->updatePassword('testuser', 'newpassword');

        $this->assertTrue($this->handler->verifyPassword('testuser', 'newpassword'));
        $this->assertFalse($this->handler->verifyPassword('testuser', 'testpassword'));
    }

    public function testUpdatePasswordThrowsExceptionForNonExistentUser(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("User `nonexistent` does not exist");

        $this->handler->updatePassword('nonexistent', 'newpassword');
    }

    public function testDataPersistsAcrossInstances(): void
    {
        $this->handler->createUser('testuser', 'testpassword');

        // 新しいインスタンスを作成
        $newHandler = new FileUserHandler($this->testFile);

        $user = $newHandler->getUser('testuser');
        $this->assertNotNull($user);
        $this->assertEquals('testuser', $user['username']);
    }
}


