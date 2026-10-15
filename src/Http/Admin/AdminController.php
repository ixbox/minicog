<?php

namespace MiniCog\Http\Admin;

use GuzzleHttp\Psr7\Response;
use MiniCog\Cognito\CognitoException;
use MiniCog\Http\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 管理画面のコントローラーの共通部分。
 *
 * 更新系の操作は POST → 303 リダイレクト → GET の流れにし、結果のメッセージは
 * Cookie（minicog_flash）で次の画面に渡す。htmx の hx-boost でもそのまま動く。
 */
abstract class AdminController
{
    private const FLASH_COOKIE = 'minicog_flash';

    public function __construct(protected readonly View $view)
    {
    }

    protected function page(ServerRequestInterface $request, string $template, array $context = [], int $status = 200): ResponseInterface
    {
        $flash = null;
        $cookie = $request->getCookieParams()[self::FLASH_COOKIE] ?? null;
        if (is_string($cookie)) {
            $decoded = json_decode($cookie, true);
            $flash = is_array($decoded) && isset($decoded['type'], $decoded['message']) ? $decoded : null;
        }

        $response = $this->view->response($template, $context + ['flash' => $flash], $status);

        return $cookie === null
            ? $response
            : $response->withAddedHeader('Set-Cookie', self::FLASH_COOKIE . '=; Path=/admin; Max-Age=0; HttpOnly; SameSite=Lax');
    }

    protected function redirect(string $location, ?string $notice = null, ?string $error = null): ResponseInterface
    {
        $response = new Response(303, ['Location' => $location]);
        $message = $error ?? $notice;
        if ($message === null) {
            return $response;
        }

        $value = rawurlencode(json_encode(['type' => $error !== null ? 'error' : 'notice', 'message' => $message], JSON_UNESCAPED_UNICODE));

        return $response->withAddedHeader('Set-Cookie', self::FLASH_COOKIE . "=$value; Path=/admin; HttpOnly; SameSite=Lax");
    }

    /**
     * 操作を実行してリダイレクトする。CognitoException は $back にエラーとして返す。
     *
     * @param callable(): (string|null) $action 戻り値があれば、そのパスにリダイレクトする
     */
    protected function attempt(string $back, string $notice, callable $action): ResponseInterface
    {
        try {
            return $this->redirect($action() ?? $back, notice: $notice);
        } catch (CognitoException $e) {
            return $this->redirect($back, error: $e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected static function form(ServerRequestInterface $request): array
    {
        return (array) $request->getParsedBody();
    }

    protected static function input(ServerRequestInterface $request, string $name): string
    {
        return trim((string) (self::form($request)[$name] ?? ''));
    }

    protected static function userPath(string $username): string
    {
        return '/admin/users/' . rawurlencode($username);
    }

    protected static function groupPath(string $name): string
    {
        return '/admin/groups/' . rawurlencode($name);
    }
}
