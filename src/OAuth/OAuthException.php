<?php

namespace MiniCog\OAuth;

/**
 * OAuth 2.0 のエラーレスポンス（RFC 6749 5.2）。
 */
final class OAuthException extends \RuntimeException
{
    public function __construct(public readonly string $error)
    {
        parent::__construct($error);
    }
}
