<?php

namespace MiniCog\Tests;

use MiniCog\MiniCog;

final class UserManagementTest extends TestCase
{
    public function testAttributesAndVerification(): void
    {
        $this->minicog->createConfirmedUser('alice', 'password123', ['email' => 'alice@example.com']);
        $tokens = $this->login('alice', 'password123');

        $result = $this->client->updateUserAttributes([
            'AccessToken' => $tokens['AccessToken'],
            'UserAttributes' => [
                ['Name' => 'email', 'Value' => 'new@example.com'],
                ['Name' => 'custom:tenant', 'Value' => 'acme'],
            ],
        ]);
        $this->assertSame('email', $result['CodeDeliveryDetailsList'][0]['AttributeName']);

        $attributes = $this->attributes($this->client->getUser(['AccessToken' => $tokens['AccessToken']])['UserAttributes']);
        $this->assertSame('new@example.com', $attributes['email']);
        $this->assertSame('false', $attributes['email_verified']);
        $this->assertSame('acme', $attributes['custom:tenant']);

        $this->client->verifyUserAttribute(['AccessToken' => $tokens['AccessToken'], 'AttributeName' => 'email', 'Code' => '123456']);
        $user = $this->client->adminGetUser(['UserPoolId' => self::POOL_ID, 'Username' => 'alice']);
        $this->assertSame('true', $this->attributes($user['UserAttributes'])['email_verified']);

        $this->client->adminDeleteUserAttributes(['UserPoolId' => self::POOL_ID, 'Username' => 'alice', 'UserAttributeNames' => ['custom:tenant']]);
        $id = $this->decode($this->login('alice', 'password123')['IdToken']);
        $this->assertArrayNotHasKey('custom:tenant', $id);
        $this->assertSame('new@example.com', $id['email']);

        $this->client->deleteUser(['AccessToken' => $tokens['AccessToken']]);
        $this->expectCognitoError('UserNotFoundException', fn () => $this->client->adminGetUser(['UserPoolId' => self::POOL_ID, 'Username' => 'alice']));
    }

    public function testGroupsAppearInTokens(): void
    {
        $this->minicog->createConfirmedUser('bob', 'password123');
        $this->client->createGroup(['UserPoolId' => self::POOL_ID, 'GroupName' => 'admins', 'Precedence' => 1]);
        $this->client->createGroup(['UserPoolId' => self::POOL_ID, 'GroupName' => 'editors', 'Precedence' => 0]);
        $this->client->adminAddUserToGroup(['UserPoolId' => self::POOL_ID, 'Username' => 'bob', 'GroupName' => 'admins']);
        $this->client->adminAddUserToGroup(['UserPoolId' => self::POOL_ID, 'Username' => 'bob', 'GroupName' => 'editors']);

        $groups = $this->client->adminListGroupsForUser(['UserPoolId' => self::POOL_ID, 'Username' => 'bob'])['Groups'];
        $this->assertSame(['editors', 'admins'], array_column($groups, 'GroupName'));

        $tokens = $this->login('bob', 'password123');
        $this->assertSame(['editors', 'admins'], $this->decode($tokens['AccessToken'])['cognito:groups']);
        $this->assertSame(['editors', 'admins'], $this->decode($tokens['IdToken'])['cognito:groups']);

        $members = $this->client->listUsersInGroup(['UserPoolId' => self::POOL_ID, 'GroupName' => 'admins'])['Users'];
        $this->assertSame(['bob'], array_column($members, 'Username'));

        $this->client->adminRemoveUserFromGroup(['UserPoolId' => self::POOL_ID, 'Username' => 'bob', 'GroupName' => 'admins']);
        $this->client->deleteGroup(['UserPoolId' => self::POOL_ID, 'GroupName' => 'editors']);
        $this->assertArrayNotHasKey('cognito:groups', $this->decode($this->login('bob', 'password123')['IdToken']));
        $this->assertSame(['admins'], array_column($this->client->listGroups(['UserPoolId' => self::POOL_ID])['Groups'], 'GroupName'));
    }

    public function testListUsersFilterAndPagination(): void
    {
        foreach (['amy', 'andy', 'ben', 'cathy', 'dan'] as $name) {
            $this->minicog->createConfirmedUser($name, 'password123', ['email' => "$name@example.com"]);
        }
        $this->client->signUp(['ClientId' => self::CLIENT_ID, 'Username' => 'eve', 'Password' => 'password123']);

        $byPrefix = $this->client->listUsers(['UserPoolId' => self::POOL_ID, 'Filter' => 'email ^= "a"']);
        $this->assertSame(['amy', 'andy'], array_column($byPrefix['Users'], 'Username'));

        $unconfirmed = $this->client->listUsers(['UserPoolId' => self::POOL_ID, 'Filter' => 'cognito:user_status = "UNCONFIRMED"']);
        $this->assertSame(['eve'], array_column($unconfirmed['Users'], 'Username'));

        $names = [];
        $token = null;
        do {
            $page = $this->client->listUsers(array_filter([
                'UserPoolId' => self::POOL_ID, 'Limit' => 2, 'PaginationToken' => $token,
            ]));
            $this->assertLessThanOrEqual(2, count($page['Users']));
            $names = [...$names, ...array_column($page['Users'], 'Username')];
            $token = $page['PaginationToken'];
        } while ($token !== null);
        $this->assertCount(6, $names);

        $this->expectCognitoError('InvalidParameterException', fn () => $this->client->listUsers([
            'UserPoolId' => self::POOL_ID, 'Filter' => 'nickname = "x"',
        ]));
    }

    public function testDescribe(): void
    {
        $pool = $this->client->describeUserPool(['UserPoolId' => self::POOL_ID])['UserPool'];
        $this->assertSame(self::POOL_ID, $pool['Id']);

        $client = $this->client->describeUserPoolClient(['UserPoolId' => self::POOL_ID, 'ClientId' => 'any'])['UserPoolClient'];
        $this->assertSame('any', $client['ClientId']);
    }

    public function testFileStoreIsSharedBetweenInstances(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'minicog');
        try {
            $first = MiniCog::create(['userPoolId' => self::POOL_ID, 'dataFile' => $file]);
            $first->createConfirmedUser('zoe', 'password123');
            $jwks = $first->jwks();

            $second = MiniCog::create(['userPoolId' => self::POOL_ID, 'dataFile' => $file]);
            $this->assertSame('zoe', $second->users->get('zoe')['username']);
            $this->assertSame($jwks, $second->jwks());
        } finally {
            unlink($file);
        }
    }

    private function attributes(array $list): array
    {
        return array_column($list, 'Value', 'Name');
    }
}
