<?php

namespace MiniCog\Cognito;

use MiniCog\Config;
use MiniCog\TokenService;
use Psr\Log\LoggerInterface;

/**
 * Cognito User Pools API（AWSCognitoIdentityProviderService）の各オペレーション。
 *
 * 入力と出力は AWS JSON 1.1 プロトコルのボディと同じ形の配列。
 * オペレーション X は op{X} メソッドで実装する。
 */
final class CognitoService
{
    public function __construct(
        private readonly Config $config,
        private readonly UserPool $users,
        private readonly TokenService $tokens,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function supports(string $operation): bool
    {
        return method_exists($this, 'op' . $operation);
    }

    public function call(string $operation, array $args): array
    {
        if (!$this->supports($operation)) {
            throw new CognitoException('UnknownOperationException', "Operation $operation is not supported by MiniCog");
        }

        return $this->{'op' . $operation}($args);
    }

    // ==================================================================
    // 認証
    // ==================================================================

    private function opInitiateAuth(array $args): array
    {
        $client = $this->client($args);
        $params = $args['AuthParameters'] ?? [];

        return match ($args['AuthFlow'] ?? null) {
            'USER_PASSWORD_AUTH' => $this->passwordAuth($client, $params),
            'REFRESH_TOKEN_AUTH', 'REFRESH_TOKEN' => $this->refreshAuth($client, $params),
            'USER_SRP_AUTH' => throw CognitoException::invalidParameter(
                'USER_SRP_AUTH is not supported by MiniCog. Use USER_PASSWORD_AUTH (Amplify: authFlowType "USER_PASSWORD_AUTH").',
            ),
            null => throw CognitoException::missing('AuthFlow'),
            default => throw CognitoException::invalidParameter("AuthFlow {$args['AuthFlow']} is not supported"),
        };
    }

    private function opAdminInitiateAuth(array $args): array
    {
        $this->assertUserPool($args);
        $client = $this->client($args);
        $params = $args['AuthParameters'] ?? [];

        return match ($args['AuthFlow'] ?? null) {
            'ADMIN_USER_PASSWORD_AUTH', 'ADMIN_NO_SRP_AUTH', 'USER_PASSWORD_AUTH' => $this->passwordAuth($client, $params),
            'REFRESH_TOKEN_AUTH', 'REFRESH_TOKEN' => $this->refreshAuth($client, $params),
            null => throw CognitoException::missing('AuthFlow'),
            default => throw CognitoException::invalidParameter("AuthFlow {$args['AuthFlow']} is not supported"),
        };
    }

    private function opRespondToAuthChallenge(array $args): array
    {
        return $this->respondToChallenge($this->client($args), $args);
    }

    private function opAdminRespondToAuthChallenge(array $args): array
    {
        $this->assertUserPool($args);

        return $this->respondToChallenge($this->client($args), $args);
    }

    private function passwordAuth(array $client, array $params): array
    {
        $username = $params['USERNAME'] ?? throw CognitoException::missing('USERNAME');
        $password = $params['PASSWORD'] ?? throw CognitoException::missing('PASSWORD');
        $this->assertSecretHash($client, $username, $params['SECRET_HASH'] ?? null);

        $user = $this->authenticate($username, $password);

        if ($user['status'] === UserPool::STATUS_FORCE_CHANGE_PASSWORD) {
            return $this->challenge($user, $client, 'NEW_PASSWORD_REQUIRED');
        }

        return $this->authenticationResult($user, $client);
    }

    private function refreshAuth(array $client, array $params): array
    {
        $refreshToken = $params['REFRESH_TOKEN'] ?? throw CognitoException::missing('REFRESH_TOKEN');

        return [
            'AuthenticationResult' => $this->tokens->refresh($refreshToken, $client['clientId']),
            'ChallengeParameters' => (object) [],
        ];
    }

    /**
     * パスワードを検証してユーザーを返す。失敗時は Cognito と同じ例外を投げる。
     */
    private function authenticate(string $login, string $password): array
    {
        $user = $this->users->get($login);

        if (!$user['enabled']) {
            throw CognitoException::notAuthorized('User is disabled.');
        }
        if (!$this->users->verifyPassword($user, $password)) {
            throw CognitoException::notAuthorized();
        }
        if ($user['status'] === UserPool::STATUS_RESET_REQUIRED) {
            throw new CognitoException('PasswordResetRequiredException', 'Password reset required for the user');
        }
        if ($user['status'] === UserPool::STATUS_UNCONFIRMED) {
            throw new CognitoException('UserNotConfirmedException', 'User is not confirmed.');
        }

        return $user;
    }

    private function authenticationResult(array $user, array $client): array
    {
        return [
            'AuthenticationResult' => $this->tokens->issueForUser($user, $client['clientId']),
            'ChallengeParameters' => (object) [],
        ];
    }

    private function challenge(array $user, array $client, string $name): array
    {
        $session = TokenService::base64url(random_bytes(48));
        $this->users->store()->update(function (array &$state) use ($session, $user, $client, $name) {
            $now = time();
            foreach ($state['challenges'] ?? [] as $id => $challenge) {
                if ($challenge['expiresAt'] < $now) {
                    unset($state['challenges'][$id]);
                }
            }
            $state['challenges'][$session] = [
                'name' => $name,
                'username' => $user['username'],
                'clientId' => $client['clientId'],
                'expiresAt' => $now + 180,
            ];
        });

        $attributes = $user['attributes'];
        unset($attributes['sub'], $attributes['email_verified'], $attributes['phone_number_verified']);

        return [
            'ChallengeName' => $name,
            'Session' => $session,
            'ChallengeParameters' => [
                'USER_ID_FOR_SRP' => $user['username'],
                'requiredAttributes' => '[]',
                'userAttributes' => json_encode((object) $attributes),
            ],
        ];
    }

    private function respondToChallenge(array $client, array $args): array
    {
        $name = $args['ChallengeName'] ?? throw CognitoException::missing('ChallengeName');
        $sessionId = $args['Session'] ?? throw CognitoException::missing('Session');
        $responses = $args['ChallengeResponses'] ?? [];

        $session = $this->users->store()->read()['challenges'][$sessionId] ?? null;
        if ($session === null || $session['expiresAt'] < time() || $session['name'] !== $name
            || $session['clientId'] !== $client['clientId']) {
            throw CognitoException::notAuthorized('Invalid session for the user, session is expired.');
        }

        $username = $responses['USERNAME'] ?? throw CognitoException::missing('USERNAME');
        $user = $this->users->get($username);
        if ($user['username'] !== $session['username']) {
            throw CognitoException::notAuthorized('Invalid session for the user.');
        }
        $this->assertSecretHash($client, $username, $responses['SECRET_HASH'] ?? null);

        if ($name !== 'NEW_PASSWORD_REQUIRED') {
            throw CognitoException::invalidParameter("ChallengeName $name is not supported by MiniCog");
        }

        $newPassword = $responses['NEW_PASSWORD'] ?? throw CognitoException::missing('NEW_PASSWORD');
        $attributes = [];
        foreach ($responses as $key => $value) {
            if (str_starts_with($key, 'userAttributes.')) {
                $attributes[substr($key, strlen('userAttributes.'))] = $value;
            }
        }

        $this->users->setPassword($user['username'], $newPassword, UserPool::STATUS_CONFIRMED);
        if ($attributes !== []) {
            $this->users->updateAttributes($user['username'], $attributes);
        }
        $this->users->store()->update(function (array &$state) use ($sessionId) {
            unset($state['challenges'][$sessionId]);
        });

        return $this->authenticationResult($this->users->get($user['username']), $client);
    }

    // ==================================================================
    // サインアップ・パスワードリセット（クライアント API）
    // ==================================================================

    private function opSignUp(array $args): array
    {
        $client = $this->client($args);
        $username = $args['Username'] ?? throw CognitoException::missing('Username');
        $password = $args['Password'] ?? throw CognitoException::missing('Password');
        $this->assertSecretHash($client, $username, $args['SecretHash'] ?? null);

        $user = $this->users->create($username, $password, self::attributeMap($args['UserAttributes'] ?? []), UserPool::STATUS_UNCONFIRMED);
        $code = $this->users->issueCode($user['username'], 'signUp');
        $this->logCode('SignUp', $user, $code);

        $result = [
            'UserConfirmed' => false,
            'UserSub' => $user['attributes']['sub'],
        ];
        if ($details = self::codeDeliveryDetails($user)) {
            $result['CodeDeliveryDetails'] = $details;
        }

        return $result;
    }

    private function opConfirmSignUp(array $args): array
    {
        $client = $this->client($args);
        $username = $args['Username'] ?? throw CognitoException::missing('Username');
        $code = $args['ConfirmationCode'] ?? throw CognitoException::missing('ConfirmationCode');
        $this->assertSecretHash($client, $username, $args['SecretHash'] ?? null);

        $this->users->modify($username, function (array &$user) use ($code) {
            if ($user['status'] !== UserPool::STATUS_UNCONFIRMED) {
                throw CognitoException::notAuthorized("User cannot be confirmed. Current status is {$user['status']}");
            }
            $this->users->consumeCode($user, 'signUp', (string) $code);
            $user['status'] = UserPool::STATUS_CONFIRMED;
            self::markContactVerified($user);
        });

        return [];
    }

    private function opResendConfirmationCode(array $args): array
    {
        $client = $this->client($args);
        $username = $args['Username'] ?? throw CognitoException::missing('Username');
        $this->assertSecretHash($client, $username, $args['SecretHash'] ?? null);

        $user = $this->users->get($username);
        if ($user['status'] !== UserPool::STATUS_UNCONFIRMED) {
            throw CognitoException::invalidParameter('User is already confirmed.');
        }
        $code = $this->users->issueCode($user['username'], 'signUp');
        $this->logCode('ResendConfirmationCode', $user, $code);

        return ['CodeDeliveryDetails' => self::codeDeliveryDetails($user) ?? (object) []];
    }

    private function opForgotPassword(array $args): array
    {
        $client = $this->client($args);
        $username = $args['Username'] ?? throw CognitoException::missing('Username');
        $this->assertSecretHash($client, $username, $args['SecretHash'] ?? null);

        $user = $this->users->get($username);
        $code = $this->users->issueCode($user['username'], 'forgotPassword');
        $this->logCode('ForgotPassword', $user, $code);

        return ['CodeDeliveryDetails' => self::codeDeliveryDetails($user) ?? (object) []];
    }

    private function opConfirmForgotPassword(array $args): array
    {
        $client = $this->client($args);
        $username = $args['Username'] ?? throw CognitoException::missing('Username');
        $code = $args['ConfirmationCode'] ?? throw CognitoException::missing('ConfirmationCode');
        $password = $args['Password'] ?? throw CognitoException::missing('Password');
        $this->assertSecretHash($client, $username, $args['SecretHash'] ?? null);

        $user = $this->users->get($username);
        $this->users->modify($user['username'], function (array &$user) use ($code) {
            $this->users->consumeCode($user, 'forgotPassword', (string) $code);
        });
        $this->users->setPassword($user['username'], $password, UserPool::STATUS_CONFIRMED);

        return [];
    }

    // ==================================================================
    // アクセストークンで操作する API
    // ==================================================================

    private function opGetUser(array $args): array
    {
        $user = $this->tokens->userFromAccessToken($args['AccessToken'] ?? null);

        return [
            'Username' => $user['username'],
            'UserAttributes' => self::attributeList($user),
        ];
    }

    private function opUpdateUserAttributes(array $args): array
    {
        $user = $this->tokens->userFromAccessToken($args['AccessToken'] ?? null);
        $attributes = self::attributeMap($args['UserAttributes'] ?? throw CognitoException::missing('UserAttributes'));

        $changed = $this->users->updateAttributes($user['username'], $attributes);

        $details = [];
        foreach ($changed as $attribute) {
            $code = $this->users->issueCode($user['username'], "attr:$attribute");
            $updated = $this->users->get($user['username']);
            $this->logCode("UpdateUserAttributes($attribute)", $updated, $code);
            $details[] = self::codeDeliveryDetails($updated, $attribute);
        }

        return ['CodeDeliveryDetailsList' => $details];
    }

    private function opGetUserAttributeVerificationCode(array $args): array
    {
        $user = $this->tokens->userFromAccessToken($args['AccessToken'] ?? null);
        $attribute = $args['AttributeName'] ?? throw CognitoException::missing('AttributeName');
        if (!isset($user['attributes'][$attribute])) {
            throw CognitoException::invalidParameter("User does not have attribute $attribute");
        }

        $code = $this->users->issueCode($user['username'], "attr:$attribute");
        $this->logCode("GetUserAttributeVerificationCode($attribute)", $user, $code);

        return ['CodeDeliveryDetails' => self::codeDeliveryDetails($user, $attribute)];
    }

    private function opVerifyUserAttribute(array $args): array
    {
        $user = $this->tokens->userFromAccessToken($args['AccessToken'] ?? null);
        $attribute = $args['AttributeName'] ?? throw CognitoException::missing('AttributeName');
        $code = $args['Code'] ?? throw CognitoException::missing('Code');

        $this->users->modify($user['username'], function (array &$user) use ($attribute, $code) {
            $this->users->consumeCode($user, "attr:$attribute", (string) $code);
            $user['attributes']["{$attribute}_verified"] = 'true';
        });

        return [];
    }

    private function opDeleteUserAttributes(array $args): array
    {
        $user = $this->tokens->userFromAccessToken($args['AccessToken'] ?? null);
        $this->users->deleteAttributes($user['username'], $args['UserAttributeNames'] ?? throw CognitoException::missing('UserAttributeNames'));

        return [];
    }

    private function opChangePassword(array $args): array
    {
        $user = $this->tokens->userFromAccessToken($args['AccessToken'] ?? null);
        $previous = $args['PreviousPassword'] ?? throw CognitoException::missing('PreviousPassword');
        $proposed = $args['ProposedPassword'] ?? throw CognitoException::missing('ProposedPassword');

        if (!$this->users->verifyPassword($user, $previous)) {
            throw CognitoException::notAuthorized();
        }
        $this->users->setPassword($user['username'], $proposed, $user['status']);

        return [];
    }

    private function opDeleteUser(array $args): array
    {
        $user = $this->tokens->userFromAccessToken($args['AccessToken'] ?? null);
        $this->users->delete($user['username']);

        return [];
    }

    private function opGlobalSignOut(array $args): array
    {
        $user = $this->tokens->userFromAccessToken($args['AccessToken'] ?? null);
        $this->tokens->signOutEverywhere($user);

        return [];
    }

    private function opRevokeToken(array $args): array
    {
        $client = $this->client($args);
        $token = $args['Token'] ?? throw CognitoException::missing('Token');
        if (($client['clientSecret'] ?? null) !== null && ($args['ClientSecret'] ?? null) !== $client['clientSecret']) {
            throw CognitoException::notAuthorized('Unable to verify secret hash for client ' . $client['clientId']);
        }
        $this->tokens->revokeRefreshToken($token, $client['clientId']);

        return [];
    }

    // ==================================================================
    // Admin API（ユーザー）
    // ==================================================================

    private function opAdminCreateUser(array $args): array
    {
        $this->assertUserPool($args);
        $username = $args['Username'] ?? throw CognitoException::missing('Username');
        $attributes = self::attributeMap($args['UserAttributes'] ?? []);

        if (($args['MessageAction'] ?? null) === 'RESEND') {
            $user = $this->users->get($username);
            if ($user['status'] !== UserPool::STATUS_FORCE_CHANGE_PASSWORD) {
                throw new CognitoException('UnsupportedUserStateException', 'Resend not possible. ' . $username . ' status is not FORCE_CHANGE_PASSWORD');
            }
        } else {
            $password = $args['TemporaryPassword'] ?? self::temporaryPassword();
            $user = $this->users->create($username, $password, $attributes, UserPool::STATUS_FORCE_CHANGE_PASSWORD);
            if (!isset($args['TemporaryPassword'])) {
                $this->logger->info('[MiniCog] AdminCreateUser temporary password', [
                    'username' => $user['username'],
                    'temporaryPassword' => $password,
                ]);
            }
        }

        return ['User' => self::userType($user)];
    }

    private function opAdminGetUser(array $args): array
    {
        $this->assertUserPool($args);
        $user = $this->users->get($args['Username'] ?? throw CognitoException::missing('Username'));

        return self::userType($user, 'UserAttributes');
    }

    private function opAdminDeleteUser(array $args): array
    {
        $this->assertUserPool($args);
        $this->users->delete($args['Username'] ?? throw CognitoException::missing('Username'));

        return [];
    }

    private function opAdminConfirmSignUp(array $args): array
    {
        $this->assertUserPool($args);
        $this->users->modify($args['Username'] ?? throw CognitoException::missing('Username'), function (array &$user) {
            if ($user['status'] !== UserPool::STATUS_UNCONFIRMED) {
                throw CognitoException::notAuthorized("User cannot be confirmed. Current status is {$user['status']}");
            }
            $user['status'] = UserPool::STATUS_CONFIRMED;
            unset($user['codes']['signUp']);
        });

        return [];
    }

    private function opAdminSetUserPassword(array $args): array
    {
        $this->assertUserPool($args);
        $username = $args['Username'] ?? throw CognitoException::missing('Username');
        $password = $args['Password'] ?? throw CognitoException::missing('Password');

        $permanent = (bool) ($args['Permanent'] ?? false);
        $this->users->setPassword(
            $username,
            $password,
            $permanent ? UserPool::STATUS_CONFIRMED : UserPool::STATUS_FORCE_CHANGE_PASSWORD,
        );

        return [];
    }

    private function opAdminResetUserPassword(array $args): array
    {
        $this->assertUserPool($args);
        $user = $this->users->get($args['Username'] ?? throw CognitoException::missing('Username'));
        $this->users->modify($user['username'], function (array &$user) {
            $user['status'] = UserPool::STATUS_RESET_REQUIRED;
        });
        $code = $this->users->issueCode($user['username'], 'forgotPassword');
        $this->logCode('AdminResetUserPassword', $user, $code);

        return [];
    }

    private function opAdminUpdateUserAttributes(array $args): array
    {
        $this->assertUserPool($args);
        $this->users->updateAttributes(
            $args['Username'] ?? throw CognitoException::missing('Username'),
            self::attributeMap($args['UserAttributes'] ?? throw CognitoException::missing('UserAttributes')),
        );

        return [];
    }

    private function opAdminDeleteUserAttributes(array $args): array
    {
        $this->assertUserPool($args);
        $this->users->deleteAttributes(
            $args['Username'] ?? throw CognitoException::missing('Username'),
            $args['UserAttributeNames'] ?? throw CognitoException::missing('UserAttributeNames'),
        );

        return [];
    }

    private function opAdminDisableUser(array $args): array
    {
        return $this->setEnabled($args, false);
    }

    private function opAdminEnableUser(array $args): array
    {
        return $this->setEnabled($args, true);
    }

    private function setEnabled(array $args, bool $enabled): array
    {
        $this->assertUserPool($args);
        $this->users->modify($args['Username'] ?? throw CognitoException::missing('Username'), function (array &$user) use ($enabled) {
            $user['enabled'] = $enabled;
        });

        return [];
    }

    private function opAdminUserGlobalSignOut(array $args): array
    {
        $this->assertUserPool($args);
        $this->tokens->signOutEverywhere($this->users->get($args['Username'] ?? throw CognitoException::missing('Username')));

        return [];
    }

    private function opListUsers(array $args): array
    {
        $this->assertUserPool($args);
        $users = $this->users->all();

        if (($filter = $args['Filter'] ?? '') !== '') {
            $users = array_values(array_filter($users, self::parseFilter($filter)));
        }

        [$page, $next] = self::paginate($users, $args['Limit'] ?? 60, $args['PaginationToken'] ?? null);
        $attributesToGet = $args['AttributesToGet'] ?? null;

        $result = ['Users' => array_map(fn (array $user) => self::userType($user, 'Attributes', $attributesToGet), $page)];
        if ($next !== null) {
            $result['PaginationToken'] = $next;
        }

        return $result;
    }

    // ==================================================================
    // Admin API（グループ）
    // ==================================================================

    private function opCreateGroup(array $args): array
    {
        $this->assertUserPool($args);
        $group = $this->users->createGroup($args['GroupName'] ?? throw CognitoException::missing('GroupName'), $args);

        return ['Group' => $this->groupType($group)];
    }

    private function opGetGroup(array $args): array
    {
        $this->assertUserPool($args);

        return ['Group' => $this->groupType($this->users->group($args['GroupName'] ?? throw CognitoException::missing('GroupName')))];
    }

    private function opDeleteGroup(array $args): array
    {
        $this->assertUserPool($args);
        $this->users->deleteGroup($args['GroupName'] ?? throw CognitoException::missing('GroupName'));

        return [];
    }

    private function opListGroups(array $args): array
    {
        $this->assertUserPool($args);
        [$page, $next] = self::paginate($this->users->groups(), $args['Limit'] ?? 60, $args['NextToken'] ?? null);

        return array_filter([
            'Groups' => array_map(fn (array $group) => $this->groupType($group), $page),
            'NextToken' => $next,
        ], fn ($value) => $value !== null);
    }

    private function opAdminAddUserToGroup(array $args): array
    {
        $this->assertUserPool($args);
        $this->users->setMembership(
            $args['Username'] ?? throw CognitoException::missing('Username'),
            $args['GroupName'] ?? throw CognitoException::missing('GroupName'),
            true,
        );

        return [];
    }

    private function opAdminRemoveUserFromGroup(array $args): array
    {
        $this->assertUserPool($args);
        $this->users->setMembership(
            $args['Username'] ?? throw CognitoException::missing('Username'),
            $args['GroupName'] ?? throw CognitoException::missing('GroupName'),
            false,
        );

        return [];
    }

    private function opAdminListGroupsForUser(array $args): array
    {
        $this->assertUserPool($args);
        $user = $this->users->get($args['Username'] ?? throw CognitoException::missing('Username'));
        [$page, $next] = self::paginate($this->users->groupsOf($user), $args['Limit'] ?? 60, $args['NextToken'] ?? null);

        return array_filter([
            'Groups' => array_map(fn (array $group) => $this->groupType($group), $page),
            'NextToken' => $next,
        ], fn ($value) => $value !== null);
    }

    private function opListUsersInGroup(array $args): array
    {
        $this->assertUserPool($args);
        $group = $this->users->group($args['GroupName'] ?? throw CognitoException::missing('GroupName'));
        $members = array_values(array_filter(
            $this->users->all(),
            fn (array $user) => in_array($user['username'], $group['members'], true),
        ));
        [$page, $next] = self::paginate($members, $args['Limit'] ?? 60, $args['NextToken'] ?? null);

        return array_filter([
            'Users' => array_map(fn (array $user) => self::userType($user), $page),
            'NextToken' => $next,
        ], fn ($value) => $value !== null);
    }

    // ==================================================================
    // ユーザープール・クライアント
    // ==================================================================

    private function opDescribeUserPool(array $args): array
    {
        $this->assertUserPool($args);
        $policy = $this->config->passwordPolicy + [
            'MinimumLength' => 8,
            'RequireUppercase' => false,
            'RequireLowercase' => false,
            'RequireNumbers' => false,
            'RequireSymbols' => false,
            'TemporaryPasswordValidityDays' => 7,
        ];

        return [
            'UserPool' => [
                'Id' => $this->config->userPoolId,
                'Name' => $this->config->userPoolName,
                'Arn' => sprintf('arn:aws:cognito-idp:%s:000000000000:userpool/%s', $this->config->region(), $this->config->userPoolId),
                'Status' => 'Enabled',
                'CreationDate' => 0,
                'LastModifiedDate' => 0,
                'Policies' => ['PasswordPolicy' => $policy],
                'UsernameAttributes' => $this->config->usernameAttributes,
                'AliasAttributes' => $this->config->aliasAttributes,
                'AutoVerifiedAttributes' => ['email'],
                'UsernameConfiguration' => ['CaseSensitive' => false],
                'MfaConfiguration' => 'OFF',
                'EstimatedNumberOfUsers' => count($this->users->all()),
                'SchemaAttributes' => array_map(fn (array $attr) => $attr + [
                    'AttributeDataType' => 'String',
                    'DeveloperOnlyAttribute' => false,
                    'Mutable' => true,
                    'Required' => false,
                ], [
                    ['Name' => 'sub', 'Mutable' => false, 'Required' => true],
                    ['Name' => 'name'],
                    ['Name' => 'given_name'],
                    ['Name' => 'family_name'],
                    ['Name' => 'preferred_username'],
                    ['Name' => 'email'],
                    ['Name' => 'email_verified', 'AttributeDataType' => 'Boolean'],
                    ['Name' => 'phone_number'],
                    ['Name' => 'phone_number_verified', 'AttributeDataType' => 'Boolean'],
                ]),
            ],
        ];
    }

    private function opDescribeUserPoolClient(array $args): array
    {
        $this->assertUserPool($args);
        $client = $this->client($args);

        return [
            'UserPoolClient' => array_filter([
                'UserPoolId' => $this->config->userPoolId,
                'ClientId' => $client['clientId'],
                'ClientName' => $client['clientName'] ?? $client['clientId'],
                'ClientSecret' => $client['clientSecret'] ?? null,
                'CallbackURLs' => $client['callbackUrls'] ?? [],
                'LogoutURLs' => $client['logoutUrls'] ?? [],
                'AllowedOAuthFlows' => ['code', 'implicit', 'client_credentials'],
                'AllowedOAuthScopes' => $client['allowedScopes'] ?? ['openid', 'email', 'profile', 'phone', TokenService::ADMIN_SCOPE],
                'AllowedOAuthFlowsUserPoolClient' => true,
                'ExplicitAuthFlows' => ['ALLOW_USER_PASSWORD_AUTH', 'ALLOW_ADMIN_USER_PASSWORD_AUTH', 'ALLOW_REFRESH_TOKEN_AUTH'],
                'SupportedIdentityProviders' => ['COGNITO'],
                'AccessTokenValidity' => $this->config->accessTokenTtl,
                'IdTokenValidity' => $this->config->idTokenTtl,
                'RefreshTokenValidity' => $this->config->refreshTokenTtl,
                'TokenValidityUnits' => ['AccessToken' => 'seconds', 'IdToken' => 'seconds', 'RefreshToken' => 'seconds'],
                'CreationDate' => 0,
                'LastModifiedDate' => 0,
            ], fn ($value) => $value !== null),
        ];
    }

    // ==================================================================
    // 補助
    // ==================================================================

    private function client(array $args): array
    {
        $clientId = $args['ClientId'] ?? throw CognitoException::missing('ClientId');

        return $this->config->client($clientId)
            ?? throw new CognitoException('ResourceNotFoundException', "User pool client $clientId does not exist.");
    }

    private function assertUserPool(array $args): void
    {
        $id = $args['UserPoolId'] ?? throw CognitoException::missing('UserPoolId');
        if ($id !== $this->config->userPoolId) {
            throw new CognitoException('ResourceNotFoundException', "User pool $id does not exist.");
        }
    }

    private function assertSecretHash(array $client, string $username, ?string $secretHash): void
    {
        $secret = $client['clientSecret'] ?? null;
        if ($secret === null) {
            return;
        }
        if ($secretHash === null) {
            throw CognitoException::notAuthorized("Client {$client['clientId']} is configured for secret but secret was not received");
        }

        $expected = base64_encode(hash_hmac('sha256', $username . $client['clientId'], $secret, true));
        if (!hash_equals($expected, $secretHash)) {
            throw CognitoException::notAuthorized("Unable to verify secret hash for client {$client['clientId']}");
        }
    }

    private function logCode(string $purpose, array $user, string $code): void
    {
        $this->logger->info("[MiniCog] $purpose code", [
            'username' => $user['username'],
            'email' => $user['attributes']['email'] ?? null,
            'code' => $code,
        ]);
    }

    private function groupType(array $group): array
    {
        return array_filter([
            'GroupName' => $group['name'],
            'UserPoolId' => $this->config->userPoolId,
            'Description' => $group['description'],
            'RoleArn' => $group['roleArn'],
            'Precedence' => $group['precedence'],
            'CreationDate' => $group['createdAt'],
            'LastModifiedDate' => $group['updatedAt'],
        ], fn ($value) => $value !== null);
    }

    /**
     * @param list<string>|null $only
     */
    private static function userType(array $user, string $attributesKey = 'Attributes', ?array $only = null): array
    {
        return [
            'Username' => $user['username'],
            $attributesKey => self::attributeList($user, $only),
            'UserCreateDate' => $user['createdAt'],
            'UserLastModifiedDate' => $user['updatedAt'],
            'Enabled' => $user['enabled'],
            'UserStatus' => $user['status'],
        ];
    }

    /**
     * @param list<string>|null $only
     * @return list<array{Name: string, Value: string}>
     */
    private static function attributeList(array $user, ?array $only = null): array
    {
        $list = [];
        foreach ($user['attributes'] as $name => $value) {
            if ($only === null || in_array($name, $only, true)) {
                $list[] = ['Name' => $name, 'Value' => (string) $value];
            }
        }

        return $list;
    }

    /**
     * @param list<array{Name: string, Value?: string}> $list
     * @return array<string, string>
     */
    private static function attributeMap(array $list): array
    {
        $map = [];
        foreach ($list as $attribute) {
            $name = $attribute['Name'] ?? throw CognitoException::missing('UserAttributes.Name');
            $map[$name] = (string) ($attribute['Value'] ?? '');
        }

        return $map;
    }

    private static function markContactVerified(array &$user): void
    {
        if (isset($user['attributes']['email'])) {
            $user['attributes']['email_verified'] = 'true';
        } elseif (isset($user['attributes']['phone_number'])) {
            $user['attributes']['phone_number_verified'] = 'true';
        }
    }

    private static function codeDeliveryDetails(array $user, ?string $attribute = null): ?array
    {
        $attribute ??= isset($user['attributes']['email']) ? 'email' : (isset($user['attributes']['phone_number']) ? 'phone_number' : null);
        if ($attribute === null || !isset($user['attributes'][$attribute])) {
            return null;
        }

        $value = $user['attributes'][$attribute];
        if ($attribute === 'email' && str_contains($value, '@')) {
            [$local, $domain] = explode('@', $value, 2);
            $destination = mb_substr($local, 0, 1) . '***@' . mb_substr($domain, 0, 1) . '***';
        } else {
            $destination = '+*******' . substr($value, -4);
        }

        return [
            'Destination' => $destination,
            'DeliveryMedium' => $attribute === 'email' ? 'EMAIL' : 'SMS',
            'AttributeName' => $attribute,
        ];
    }

    /**
     * ListUsers の Filter（例: email ^= "foo", cognito:user_status = "CONFIRMED"）を述語に変換する。
     */
    private static function parseFilter(string $filter): \Closure
    {
        if (!preg_match('/^\s*([\w:]+)\s*(\^?=)\s*"((?:[^"\\\\]|\\\\.)*)"\s*$/', $filter, $m)) {
            throw CognitoException::invalidParameter('Error while parsing filter.');
        }
        [, $attribute, $operator, $value] = $m;
        $value = stripslashes($value);

        $supported = ['username', 'email', 'phone_number', 'name', 'given_name', 'family_name', 'preferred_username', 'cognito:user_status', 'status', 'sub'];
        if (!in_array($attribute, $supported, true)) {
            throw CognitoException::invalidParameter("Invalid search attribute: $attribute");
        }

        return function (array $user) use ($attribute, $operator, $value): bool {
            $actual = match ($attribute) {
                'username' => $user['username'],
                'cognito:user_status' => $user['status'],
                'status' => $user['enabled'] ? 'Enabled' : 'Disabled',
                default => $user['attributes'][$attribute] ?? null,
            };
            if ($actual === null) {
                return false;
            }

            return $operator === '='
                ? strcasecmp($actual, $value) === 0
                : stripos($actual, $value) === 0;
        };
    }

    /**
     * @return array{0: list<mixed>, 1: string|null}
     */
    private static function paginate(array $items, int $limit, ?string $token): array
    {
        if ($limit < 0 || $limit > 60) {
            throw CognitoException::invalidParameter('Limit must be between 0 and 60');
        }
        $limit = $limit === 0 ? 60 : $limit;
        $offset = 0;
        if ($token !== null) {
            $decoded = base64_decode($token, true);
            if ($decoded === false || !ctype_digit($decoded)) {
                throw CognitoException::invalidParameter('Invalid pagination token');
            }
            $offset = (int) $decoded;
        }

        $page = array_slice($items, $offset, $limit);
        $next = $offset + $limit < count($items) ? base64_encode((string) ($offset + $limit)) : null;

        return [$page, $next];
    }

    private static function temporaryPassword(): string
    {
        return 'Tmp' . bin2hex(random_bytes(4)) . 'A1!';
    }
}
