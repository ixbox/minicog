<?php

namespace MiniCog\Http\Admin;

use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use MiniCog\Cognito\UserPool;
use MiniCog\Config;
use MiniCog\Http\View;
use MiniCog\TokenService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * /admin/tokens: 任意のユーザーのトークン発行と、JWT のデコード・検証。
 *
 * 発行とデコードの結果は htmx で画面の一部（フラグメント）として返す。
 */
final class TokenController extends AdminController
{
    public function __construct(
        View $view,
        private readonly Config $config,
        private readonly UserPool $users,
        private readonly TokenService $tokens,
    ) {
        parent::__construct($view);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->page($request, 'admin/tokens.html.twig', [
            'section' => 'tokens',
            'users' => $this->users->all(),
            'selected' => $request->getQueryParams()['username'] ?? null,
            'defaultScope' => TokenService::ADMIN_SCOPE . ' openid email profile',
        ]);
    }

    public function issue(ServerRequestInterface $request): ResponseInterface
    {
        $username = self::input($request, 'username');
        $clientId = self::input($request, 'client_id');
        $scopes = array_values(array_filter(preg_split('/\s+/', self::input($request, 'scope')) ?: []));

        $error = match (true) {
            $username === '' => 'ユーザーを選んでください。',
            $clientId === '' => 'クライアント ID を入力してください。',
            $this->config->client($clientId) === null => "クライアント $clientId は設定にありません。",
            $scopes === [] => 'スコープを入力してください。',
            default => null,
        };
        $user = $error === null ? $this->users->find($username) : null;
        if ($error === null && $user === null) {
            $error = "ユーザー $username は存在しません。";
        }
        if ($error !== null) {
            return $this->view->response('admin/_token_result.html.twig', ['error' => $error], 422);
        }

        $issued = $this->tokens->issueForUser($user, $clientId, $scopes, [
            'includeIdToken' => in_array('openid', $scopes, true) || in_array(TokenService::ADMIN_SCOPE, $scopes, true),
        ]);

        $tokens = [];
        foreach (['IdToken' => 'ID トークン', 'AccessToken' => 'アクセストークン', 'RefreshToken' => 'リフレッシュトークン'] as $key => $label) {
            if (isset($issued[$key])) {
                $tokens[] = [
                    'key' => $key,
                    'label' => $label,
                    'value' => $issued[$key],
                    'decoded' => $key === 'RefreshToken' ? null : self::decodeParts($issued[$key]),
                ];
            }
        }

        return $this->view->response('admin/_token_result.html.twig', [
            'error' => null,
            'user' => $user,
            'clientId' => $clientId,
            'tokens' => $tokens,
            'accessToken' => $issued['AccessToken'],
            'warning' => $user['enabled'] && $user['status'] === UserPool::STATUS_CONFIRMED
                ? null
                : "このユーザーは {$user['status']}" . ($user['enabled'] ? '' : '（無効）') . ' のため、実際の Cognito ではサインインできません。',
        ]);
    }

    public function decode(ServerRequestInterface $request): ResponseInterface
    {
        $jwt = preg_replace('/^Bearer\s+/i', '', self::input($request, 'jwt'));
        $parts = self::decodeParts($jwt);
        if ($parts === null) {
            return $this->view->response('admin/_decoded.html.twig', ['error' => 'JWT として読み取れませんでした。'], 422);
        }

        try {
            JWT::decode($jwt, JWK::parseKeySet($this->tokens->jwks()));
            $verification = ['ok' => true, 'message' => '署名は有効で、期限内です。'];
        } catch (ExpiredException) {
            $verification = ['ok' => false, 'message' => '署名は有効ですが、期限切れです。'];
        } catch (\Throwable $e) {
            $verification = ['ok' => false, 'message' => '検証に失敗しました: ' . $e->getMessage()];
        }

        $payload = $parts['payload'];
        if (($payload['iss'] ?? null) !== $this->config->issuer()) {
            $verification['issuer'] = 'iss がこの MiniCog の issuer（' . $this->config->issuer() . '）と一致しません。';
        }

        return $this->view->response('admin/_decoded.html.twig', [
            'error' => null,
            'header' => $parts['header'],
            'payload' => $payload,
            'verification' => $verification,
            'times' => array_filter(
                ['iat' => $payload['iat'] ?? null, 'exp' => $payload['exp'] ?? null, 'auth_time' => $payload['auth_time'] ?? null],
                'is_int',
            ),
        ]);
    }

    /**
     * 署名を検証せずにヘッダーとペイロードを取り出す。
     *
     * @return array{header: array, payload: array}|null
     */
    private static function decodeParts(string $jwt): ?array
    {
        $segments = explode('.', $jwt);
        if (count($segments) !== 3) {
            return null;
        }

        try {
            $header = json_decode(JWT::urlsafeB64Decode($segments[0]), true, flags: JSON_THROW_ON_ERROR);
            $payload = json_decode(JWT::urlsafeB64Decode($segments[1]), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        return is_array($header) && is_array($payload) ? ['header' => $header, 'payload' => $payload] : null;
    }
}
