<?php

namespace MiniCog\Tests;

use Aws\Api\DateTimeResult;

final class AuthFlowTest extends TestCase
{
    public function testSignUpConfirmAndSignIn(): void
    {
        $signUp = $this->client->signUp([
            'ClientId' => self::CLIENT_ID,
            'Username' => 'alice',
            'Password' => 'password123',
            'UserAttributes' => [['Name' => 'email', 'Value' => 'alice@example.com']],
        ]);
        $this->assertFalse($signUp['UserConfirmed']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $signUp['UserSub']);
        $this->assertSame('a***@e***', $signUp['CodeDeliveryDetails']['Destination']);

        $this->expectCognitoError('UserNotConfirmedException', fn () => $this->login('alice', 'password123'));
        $this->expectCognitoError('CodeMismatchException', fn () => $this->client->confirmSignUp([
            'ClientId' => self::CLIENT_ID, 'Username' => 'alice', 'ConfirmationCode' => '000000',
        ]));

        $this->client->confirmSignUp(['ClientId' => self::CLIENT_ID, 'Username' => 'alice', 'ConfirmationCode' => '123456']);
        $tokens = $this->login('alice', 'password123');

        $this->assertSame('Bearer', $tokens['TokenType']);
        $this->assertSame(3600, $tokens['ExpiresIn']);
        $this->assertNotEmpty($tokens['RefreshToken']);

        $id = $this->decode($tokens['IdToken']);
        $this->assertSame($signUp['UserSub'], $id['sub']);
        $this->assertSame('id', $id['token_use']);
        $this->assertSame(self::CLIENT_ID, $id['aud']);
        $this->assertSame('alice', $id['cognito:username']);
        $this->assertSame('alice@example.com', $id['email']);
        $this->assertTrue($id['email_verified']);
        $this->assertSame('http://localhost:9229/' . self::POOL_ID, $id['iss']);

        $access = $this->decode($tokens['AccessToken']);
        $this->assertSame('access', $access['token_use']);
        $this->assertSame(self::CLIENT_ID, $access['client_id']);
        $this->assertSame('aws.cognito.signin.user.admin', $access['scope']);
        $this->assertSame('alice', $access['username']);
        $this->assertSame($id['origin_jti'], $access['origin_jti']);
    }

    public function testAuthErrors(): void
    {
        $this->minicog->createConfirmedUser('bob', 'password123');

        $this->expectCognitoError('NotAuthorizedException', fn () => $this->login('bob', 'wrong-password'));
        $this->expectCognitoError('UserNotFoundException', fn () => $this->login('nobody', 'password123'));
        $this->expectCognitoError('InvalidParameterException', fn () => $this->client->initiateAuth([
            'AuthFlow' => 'USER_SRP_AUTH', 'ClientId' => self::CLIENT_ID, 'AuthParameters' => ['USERNAME' => 'bob', 'SRP_A' => 'x'],
        ]));
        $this->expectCognitoError('InvalidPasswordException', fn () => $this->client->signUp([
            'ClientId' => self::CLIENT_ID, 'Username' => 'carol', 'Password' => 'short',
        ]));
        $this->expectCognitoError('UsernameExistsException', fn () => $this->client->signUp([
            'ClientId' => self::CLIENT_ID, 'Username' => 'BOB', 'Password' => 'password123',
        ]));
        $this->expectCognitoError('ResourceNotFoundException', fn () => $this->client->adminGetUser([
            'UserPoolId' => 'ap-northeast-1_other', 'Username' => 'bob',
        ]));

        $this->client->adminDisableUser(['UserPoolId' => self::POOL_ID, 'Username' => 'bob']);
        $e = $this->expectCognitoError('NotAuthorizedException', fn () => $this->login('bob', 'password123'));
        $this->assertSame('User is disabled.', $e->getAwsErrorMessage());
    }

    public function testAdminCreateUserRequiresNewPassword(): void
    {
        $created = $this->client->adminCreateUser([
            'UserPoolId' => self::POOL_ID,
            'Username' => 'dave',
            'TemporaryPassword' => 'Temp1234!',
            'UserAttributes' => [['Name' => 'email', 'Value' => 'dave@example.com'], ['Name' => 'email_verified', 'Value' => 'true']],
            'MessageAction' => 'SUPPRESS',
        ]);
        $this->assertSame('FORCE_CHANGE_PASSWORD', $created['User']['UserStatus']);
        $this->assertInstanceOf(DateTimeResult::class, $created['User']['UserCreateDate']);

        $challenge = $this->client->adminInitiateAuth([
            'UserPoolId' => self::POOL_ID,
            'ClientId' => self::CLIENT_ID,
            'AuthFlow' => 'ADMIN_USER_PASSWORD_AUTH',
            'AuthParameters' => ['USERNAME' => 'dave', 'PASSWORD' => 'Temp1234!'],
        ]);
        $this->assertSame('NEW_PASSWORD_REQUIRED', $challenge['ChallengeName']);
        $this->assertNull($challenge['AuthenticationResult']);

        $result = $this->client->adminRespondToAuthChallenge([
            'UserPoolId' => self::POOL_ID,
            'ClientId' => self::CLIENT_ID,
            'ChallengeName' => 'NEW_PASSWORD_REQUIRED',
            'Session' => $challenge['Session'],
            'ChallengeResponses' => ['USERNAME' => 'dave', 'NEW_PASSWORD' => 'newpassword1', 'userAttributes.name' => 'Dave'],
        ]);
        $this->assertSame('Dave', $this->decode($result['AuthenticationResult']['IdToken'])['name']);

        $user = $this->client->adminGetUser(['UserPoolId' => self::POOL_ID, 'Username' => 'dave']);
        $this->assertSame('CONFIRMED', $user['UserStatus']);
        $this->login('dave', 'newpassword1');
    }

    public function testRefreshAndGlobalSignOut(): void
    {
        $this->minicog->createConfirmedUser('erin', 'password123');
        $tokens = $this->login('erin', 'password123');

        $refreshed = $this->client->initiateAuth([
            'AuthFlow' => 'REFRESH_TOKEN_AUTH',
            'ClientId' => self::CLIENT_ID,
            'AuthParameters' => ['REFRESH_TOKEN' => $tokens['RefreshToken']],
        ])['AuthenticationResult'];
        $this->assertArrayNotHasKey('RefreshToken', array_filter($refreshed));
        $this->assertSame('erin', $this->decode($refreshed['AccessToken'])['username']);

        $this->assertSame('erin', $this->client->getUser(['AccessToken' => $refreshed['AccessToken']])['Username']);

        $this->client->globalSignOut(['AccessToken' => $tokens['AccessToken']]);

        $this->expectCognitoError('NotAuthorizedException', fn () => $this->client->getUser(['AccessToken' => $refreshed['AccessToken']]));
        $this->expectCognitoError('NotAuthorizedException', fn () => $this->client->initiateAuth([
            'AuthFlow' => 'REFRESH_TOKEN_AUTH',
            'ClientId' => self::CLIENT_ID,
            'AuthParameters' => ['REFRESH_TOKEN' => $tokens['RefreshToken']],
        ]));
    }

    public function testRevokeToken(): void
    {
        $this->minicog->createConfirmedUser('frank', 'password123');
        $tokens = $this->login('frank', 'password123');

        $this->client->revokeToken(['ClientId' => self::CLIENT_ID, 'Token' => $tokens['RefreshToken']]);
        $this->expectCognitoError('NotAuthorizedException', fn () => $this->client->getUser(['AccessToken' => $tokens['AccessToken']]));
    }

    public function testForgotAndChangePassword(): void
    {
        $this->minicog->createConfirmedUser('grace', 'password123', ['email' => 'grace@example.com']);

        $forgot = $this->client->forgotPassword(['ClientId' => self::CLIENT_ID, 'Username' => 'grace']);
        $this->assertSame('EMAIL', $forgot['CodeDeliveryDetails']['DeliveryMedium']);
        $this->client->confirmForgotPassword([
            'ClientId' => self::CLIENT_ID, 'Username' => 'grace', 'ConfirmationCode' => '123456', 'Password' => 'resetpass1',
        ]);
        $tokens = $this->login('grace', 'resetpass1');

        $this->expectCognitoError('NotAuthorizedException', fn () => $this->client->changePassword([
            'AccessToken' => $tokens['AccessToken'], 'PreviousPassword' => 'wrong', 'ProposedPassword' => 'changed12',
        ]));
        $this->client->changePassword([
            'AccessToken' => $tokens['AccessToken'], 'PreviousPassword' => 'resetpass1', 'ProposedPassword' => 'changed12',
        ]);
        $this->login('grace', 'changed12');

        $this->client->adminResetUserPassword(['UserPoolId' => self::POOL_ID, 'Username' => 'grace']);
        $this->expectCognitoError('PasswordResetRequiredException', fn () => $this->login('grace', 'changed12'));
    }

    public function testSecretHashIsRequiredForConfidentialClient(): void
    {
        $this->boot(['clients' => [['clientId' => 'confidential', 'clientSecret' => 's3cret']]]);
        $this->minicog->createConfirmedUser('heidi', 'password123');

        $this->expectCognitoError('ResourceNotFoundException', fn () => $this->login('heidi', 'password123', 'unknown'));
        $this->expectCognitoError('NotAuthorizedException', fn () => $this->login('heidi', 'password123', 'confidential'));

        $tokens = $this->client->initiateAuth([
            'AuthFlow' => 'USER_PASSWORD_AUTH',
            'ClientId' => 'confidential',
            'AuthParameters' => [
                'USERNAME' => 'heidi',
                'PASSWORD' => 'password123',
                'SECRET_HASH' => base64_encode(hash_hmac('sha256', 'heidiconfidential', 's3cret', true)),
            ],
        ])['AuthenticationResult'];
        $this->assertSame('confidential', $this->decode($tokens['IdToken'])['aud']);
    }

    public function testEmailAsUsername(): void
    {
        $this->boot(['usernameAttributes' => ['email']]);

        $signUp = $this->client->signUp([
            'ClientId' => self::CLIENT_ID, 'Username' => 'ivan@example.com', 'Password' => 'password123',
        ]);
        $this->client->confirmSignUp(['ClientId' => self::CLIENT_ID, 'Username' => 'IVAN@example.com', 'ConfirmationCode' => '123456']);

        $id = $this->decode($this->login('ivan@example.com', 'password123')['IdToken']);
        $this->assertSame($signUp['UserSub'], $id['cognito:username']);
        $this->assertSame('ivan@example.com', $id['email']);

        $this->expectCognitoError('InvalidParameterException', fn () => $this->client->signUp([
            'ClientId' => self::CLIENT_ID, 'Username' => 'not-an-email', 'Password' => 'password123',
        ]));
    }
}
