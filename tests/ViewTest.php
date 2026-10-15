<?php

namespace MiniCog\Tests;

use GuzzleHttp\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;

final class ViewTest extends TestCase
{
    public function testAdminPageRendersAndEscapes(): void
    {
        $this->minicog->createConfirmedUser('<script>alert(1)</script>', 'password123', ['email' => 'x@example.com']);
        $this->client->signUp(['ClientId' => 'c', 'Username' => 'pending', 'Password' => 'password123']);
        $this->client->createGroup(['UserPoolId' => self::POOL_ID, 'GroupName' => 'admins']);
        $this->client->adminAddUserToGroup(['UserPoolId' => self::POOL_ID, 'Username' => 'pending', 'GroupName' => 'admins']);

        $response = $this->get('/admin/users');
        $html = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringContainsString('href="/admin/users/%3Cscript%3Ealert%281%29%3C%2Fscript%3E"', $html);
        $this->assertStringContainsString('<code>123456</code> <small class="muted">signUp</small>', $html);
        $this->assertStringContainsString('admins', $html);
    }

    public function testErrorPageEscapes(): void
    {
        $response = $this->get('/oauth2/authorize?' . http_build_query([
            'response_type' => 'code', 'client_id' => 'c', 'redirect_uri' => '',
        ]));
        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('redirect_uri が許可されていません', (string) $response->getBody());
    }

    public function testTemplatesCanBeOverridden(): void
    {
        $dir = sys_get_temp_dir() . '/minicog-templates-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/login.html.twig', <<<'TWIG'
            {% extends 'layout.html.twig' %}
            {% block title %}Custom{% endblock %}
            {% block body %}<h1>Acme Login</h1>{{ params.client_id }} {{ config.userPoolId }}{% endblock %}
            TWIG);

        try {
            $this->boot(['templatesDir' => $dir]);
            $html = (string) $this->get('/oauth2/authorize?' . http_build_query([
                'response_type' => 'code', 'client_id' => 'web', 'redirect_uri' => 'http://localhost/cb',
            ]))->getBody();

            $this->assertStringContainsString('<h1>Acme Login</h1>web ' . self::POOL_ID, $html);
            $this->assertStringContainsString('<title>Custom - MiniCog</title>', $html, '同梱の layout を継承できる');

            // 差し替えていないテンプレートは同梱のものが使われる
            $this->assertStringContainsString('新規ユーザー作成', (string) $this->get('/admin/users')->getBody());
        } finally {
            unlink($dir . '/login.html.twig');
            rmdir($dir);
        }
    }

    private function get(string $path): ResponseInterface
    {
        return $this->minicog->handle(new ServerRequest('GET', 'http://localhost:9229' . $path));
    }
}
