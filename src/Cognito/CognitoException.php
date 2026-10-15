<?php

namespace MiniCog\Cognito;

/**
 * Cognito の API エラー。type は Cognito の例外名（NotAuthorizedException など）。
 */
final class CognitoException extends \RuntimeException
{
    public function __construct(
        public readonly string $type,
        string $message,
        public readonly int $status = 400,
    ) {
        parent::__construct($message);
    }

    public static function invalidParameter(string $message): self
    {
        return new self('InvalidParameterException', $message);
    }

    public static function notAuthorized(string $message = 'Incorrect username or password.'): self
    {
        return new self('NotAuthorizedException', $message);
    }

    public static function userNotFound(): self
    {
        return new self('UserNotFoundException', 'User does not exist.');
    }

    public static function missing(string ...$names): self
    {
        return self::invalidParameter('Missing required parameter ' . implode(', ', $names));
    }
}
