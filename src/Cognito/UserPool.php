<?php

namespace MiniCog\Cognito;

use MiniCog\Config;
use MiniCog\Store\StoreInterface;

/**
 * ユーザーとグループの永続化と、Cognito のユーザーモデル（ステータス、属性、エイリアス）を扱う。
 *
 * ユーザーは state['users'][小文字ユーザー名] に保存する:
 *   username, passwordHash, attributes(map, sub を含む), status, enabled, createdAt, updatedAt,
 *   codes: {signUp?, forgotPassword?, "attr:<name>"?}
 */
final class UserPool
{
    public const STATUS_UNCONFIRMED = 'UNCONFIRMED';
    public const STATUS_CONFIRMED = 'CONFIRMED';
    public const STATUS_FORCE_CHANGE_PASSWORD = 'FORCE_CHANGE_PASSWORD';
    public const STATUS_RESET_REQUIRED = 'RESET_REQUIRED';

    public function __construct(
        private readonly StoreInterface $store,
        private readonly Config $config,
    ) {
    }

    public function store(): StoreInterface
    {
        return $this->store;
    }

    // ------------------------------------------------------------------
    // 参照
    // ------------------------------------------------------------------

    /**
     * ユーザー名、またはエイリアス（email など）でユーザーを探す。
     */
    public function find(string $login): ?array
    {
        return self::findIn($this->store->read(), $login, $this->loginAttributes());
    }

    public function get(string $login): array
    {
        return $this->find($login) ?? throw CognitoException::userNotFound();
    }

    public function findBySub(string $sub): ?array
    {
        foreach ($this->store->read()['users'] ?? [] as $user) {
            if ($user['attributes']['sub'] === $sub) {
                return $user;
            }
        }

        return null;
    }

    /**
     * @return list<array>
     */
    public function all(): array
    {
        $users = array_values($this->store->read()['users'] ?? []);
        usort($users, fn (array $a, array $b) => [$a['createdAt'], $a['username']] <=> [$b['createdAt'], $b['username']]);

        return $users;
    }

    // ------------------------------------------------------------------
    // 作成・更新
    // ------------------------------------------------------------------

    /**
     * @param array<string, string> $attributes
     */
    public function create(string $username, ?string $password, array $attributes, string $status): array
    {
        $attributes = $this->normalizeAttributes($attributes);
        $sub = self::uuid();

        // email などをユーザー名に使うプールでは、ユーザー名は sub になり、入力値は属性に入る
        if ($this->config->usernameAttributes !== []) {
            $attribute = $this->usernameAttributeFor($username);
            if ($attribute === null) {
                throw CognitoException::invalidParameter('Username should be ' . implode(' or ', $this->config->usernameAttributes) . '.');
            }
            $attributes[$attribute] = $username;
            $username = $sub;
        }

        if ($password !== null) {
            $this->assertPasswordPolicy($password);
        }

        $attributes = ['sub' => $sub] + $attributes;
        $now = time();
        $user = [
            'username' => $username,
            'passwordHash' => $password === null ? null : self::hash($password),
            'attributes' => $attributes,
            'status' => $status,
            'enabled' => true,
            'createdAt' => $now,
            'updatedAt' => $now,
            'codes' => [],
        ];

        return $this->store->update(function (array &$state) use ($user) {
            $loginAttributes = $this->loginAttributes();
            if (self::findIn($state, $user['username'], []) !== null) {
                throw new CognitoException('UsernameExistsException', 'User already exists');
            }
            foreach ($loginAttributes as $attribute) {
                $value = $user['attributes'][$attribute] ?? null;
                if ($value !== null && self::findIn($state, $value, [$attribute]) !== null) {
                    throw new CognitoException(
                        'UsernameExistsException',
                        "An account with the given $attribute already exists.",
                    );
                }
            }

            $state['users'][self::key($user['username'])] = $user;

            return $user;
        });
    }

    /**
     * ユーザーを排他的に更新する。コールバックはユーザー配列を参照で受け取る。
     *
     * @template T
     * @param callable(array &$user): T $callback
     * @return T
     */
    public function modify(string $login, callable $callback): mixed
    {
        return $this->store->update(function (array &$state) use ($login, $callback) {
            $user = self::findIn($state, $login, $this->loginAttributes()) ?? throw CognitoException::userNotFound();
            $key = self::key($user['username']);

            $result = $callback($state['users'][$key]);
            $state['users'][$key]['updatedAt'] = time();

            return $result;
        });
    }

    public function delete(string $login): void
    {
        $this->store->update(function (array &$state) use ($login) {
            $user = self::findIn($state, $login, $this->loginAttributes()) ?? throw CognitoException::userNotFound();
            unset($state['users'][self::key($user['username'])]);

            foreach ($state['groups'] ?? [] as $name => $group) {
                $state['groups'][$name]['members'] = array_values(array_diff($group['members'], [$user['username']]));
            }
            foreach ($state['sessions'] ?? [] as $id => $session) {
                if (($session['username'] ?? null) === $user['username']) {
                    unset($state['sessions'][$id]);
                }
            }
        });
    }

    public function setPassword(string $login, string $password, string $status): void
    {
        $this->assertPasswordPolicy($password);
        $this->modify($login, function (array &$user) use ($password, $status) {
            $user['passwordHash'] = self::hash($password);
            $user['status'] = $status;
        });
    }

    public function verifyPassword(array $user, string $password): bool
    {
        return $user['passwordHash'] !== null && password_verify($password, $user['passwordHash']);
    }

    /**
     * @param array<string, string> $attributes
     * @return list<string> 値が変わり、検証が必要になった属性（email / phone_number）
     */
    public function updateAttributes(string $login, array $attributes): array
    {
        $attributes = $this->normalizeAttributes($attributes);
        if (array_key_exists('sub', $attributes)) {
            throw CognitoException::invalidParameter('Cannot modify an immutable attribute: sub');
        }

        return $this->modify($login, function (array &$user) use ($attributes) {
            $needsVerification = [];
            foreach ($attributes as $name => $value) {
                $previous = $user['attributes'][$name] ?? null;
                $user['attributes'][$name] = $value;
                if (in_array($name, ['email', 'phone_number'], true) && $previous !== $value
                    && !array_key_exists("{$name}_verified", $attributes)) {
                    $user['attributes']["{$name}_verified"] = 'false';
                    $needsVerification[] = $name;
                }
            }

            return $needsVerification;
        });
    }

    /**
     * @param list<string> $names
     */
    public function deleteAttributes(string $login, array $names): void
    {
        if (in_array('sub', $names, true)) {
            throw CognitoException::invalidParameter('Cannot delete an immutable attribute: sub');
        }

        $this->modify($login, function (array &$user) use ($names) {
            foreach ($names as $name) {
                unset($user['attributes'][$name]);
            }
        });
    }

    // ------------------------------------------------------------------
    // 確認コード
    // ------------------------------------------------------------------

    public function issueCode(string $login, string $purpose): string
    {
        $code = $this->config->fixedCode ?? sprintf('%06d', random_int(0, 999999));
        $this->modify($login, function (array &$user) use ($purpose, $code) {
            $user['codes'][$purpose] = ['code' => $code, 'expiresAt' => time() + 86400];
        });

        return $code;
    }

    public function consumeCode(array &$user, string $purpose, string $code): void
    {
        $entry = $user['codes'][$purpose] ?? null;
        if ($entry === null || !hash_equals((string) $entry['code'], $code)) {
            throw new CognitoException('CodeMismatchException', 'Invalid verification code provided, please try again.');
        }
        if ($entry['expiresAt'] < time()) {
            throw new CognitoException('ExpiredCodeException', 'Invalid code provided, please request a code again.');
        }

        unset($user['codes'][$purpose]);
    }

    // ------------------------------------------------------------------
    // グループ
    // ------------------------------------------------------------------

    public function createGroup(string $name, array $fields): array
    {
        return $this->store->update(function (array &$state) use ($name, $fields) {
            if (isset($state['groups'][$name])) {
                throw new CognitoException('GroupExistsException', 'A group with the name already exists.');
            }
            $now = time();
            $state['groups'][$name] = [
                'name' => $name,
                'description' => $fields['Description'] ?? null,
                'precedence' => $fields['Precedence'] ?? null,
                'roleArn' => $fields['RoleArn'] ?? null,
                'createdAt' => $now,
                'updatedAt' => $now,
                'members' => [],
            ];

            return $state['groups'][$name];
        });
    }

    public function group(string $name): array
    {
        return $this->store->read()['groups'][$name]
            ?? throw new CognitoException('ResourceNotFoundException', 'Group not found.');
    }

    /**
     * @return list<array>
     */
    public function groups(): array
    {
        $groups = array_values($this->store->read()['groups'] ?? []);
        usort($groups, fn (array $a, array $b) => strcmp($a['name'], $b['name']));

        return $groups;
    }

    public function deleteGroup(string $name): void
    {
        $this->store->update(function (array &$state) use ($name) {
            if (!isset($state['groups'][$name])) {
                throw new CognitoException('ResourceNotFoundException', 'Group not found.');
            }
            unset($state['groups'][$name]);
        });
    }

    public function setMembership(string $login, string $groupName, bool $member): void
    {
        $user = $this->get($login);
        $this->store->update(function (array &$state) use ($user, $groupName, $member) {
            if (!isset($state['groups'][$groupName])) {
                throw new CognitoException('ResourceNotFoundException', 'Group not found.');
            }
            $members = array_diff($state['groups'][$groupName]['members'], [$user['username']]);
            if ($member) {
                $members[] = $user['username'];
            }
            $state['groups'][$groupName]['members'] = array_values($members);
        });
    }

    /**
     * ユーザーが所属するグループを precedence 順で返す。
     *
     * @return list<array>
     */
    public function groupsOf(array $user): array
    {
        $groups = array_values(array_filter(
            $this->store->read()['groups'] ?? [],
            fn (array $group) => in_array($user['username'], $group['members'], true),
        ));
        usort($groups, fn (array $a, array $b) => [$a['precedence'] ?? PHP_INT_MAX, $a['name']] <=> [$b['precedence'] ?? PHP_INT_MAX, $b['name']]);

        return $groups;
    }

    // ------------------------------------------------------------------
    // 補助
    // ------------------------------------------------------------------

    /**
     * サインインに使える属性（usernameAttributes + aliasAttributes）。
     *
     * @return list<string>
     */
    private function loginAttributes(): array
    {
        return array_values(array_unique([...$this->config->usernameAttributes, ...$this->config->aliasAttributes]));
    }

    private function usernameAttributeFor(string $username): ?string
    {
        foreach ($this->config->usernameAttributes as $attribute) {
            $isEmail = str_contains($username, '@');
            if (($attribute === 'email' && $isEmail) || ($attribute === 'phone_number' && !$isEmail && str_starts_with($username, '+'))) {
                return $attribute;
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $attributes
     * @return array<string, string>
     */
    private function normalizeAttributes(array $attributes): array
    {
        $normalized = [];
        foreach ($attributes as $name => $value) {
            $normalized[(string) $name] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        return $normalized;
    }

    private function assertPasswordPolicy(string $password): void
    {
        $policy = $this->config->passwordPolicy;
        $checks = [
            'MinimumLength' => fn () => mb_strlen($password) >= ($policy['MinimumLength'] ?? 8),
            'RequireUppercase' => fn () => preg_match('/[A-Z]/', $password) === 1,
            'RequireLowercase' => fn () => preg_match('/[a-z]/', $password) === 1,
            'RequireNumbers' => fn () => preg_match('/\d/', $password) === 1,
            'RequireSymbols' => fn () => preg_match('/[^A-Za-z0-9\s]/', $password) === 1,
        ];
        $messages = [
            'MinimumLength' => 'Password not long enough',
            'RequireUppercase' => 'Password must have uppercase characters',
            'RequireLowercase' => 'Password must have lowercase characters',
            'RequireNumbers' => 'Password must have numeric characters',
            'RequireSymbols' => 'Password must have symbol characters',
        ];

        foreach ($checks as $rule => $check) {
            $enabled = $rule === 'MinimumLength' || ($policy[$rule] ?? false);
            if ($enabled && !$check()) {
                throw new CognitoException(
                    'InvalidPasswordException',
                    'Password did not conform with policy: ' . $messages[$rule],
                );
            }
        }
    }

    /**
     * @param list<string> $attributes
     */
    private static function findIn(array $state, string $login, array $attributes): ?array
    {
        $users = $state['users'] ?? [];
        if (isset($users[self::key($login)])) {
            return $users[self::key($login)];
        }

        foreach ($attributes as $attribute) {
            foreach ($users as $user) {
                $value = $user['attributes'][$attribute] ?? null;
                if ($value !== null && strcasecmp($value, $login) === 0) {
                    return $user;
                }
            }
        }

        return null;
    }

    private static function key(string $username): string
    {
        return mb_strtolower($username);
    }

    private static function hash(string $password): string
    {
        // ローカル開発用なのでコストは最小にしてテストを速くする
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]);
    }

    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr(ord($bytes[6]) & 0x0f | 0x40);
        $bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
