<?php

namespace MiniCog\Http;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Twig で HTML を描画する。
 *
 * テンプレートは $overrideDir → 同梱の templates/ の順に探すので、
 * 利用者は差し替えたいファイル（login.html.twig など）だけを置けばよい。
 * Twig の初期化は最初の描画まで遅らせる（SDK のインプロセス利用では HTML を使わないため）。
 */
final class View
{
    public const ASSETS_DIR = __DIR__ . '/../../assets';

    private ?Environment $twig = null;

    public function __construct(
        private readonly array $globals = [],
        private readonly ?string $overrideDir = null,
    ) {
    }

    public function render(string $template, array $context = []): string
    {
        return $this->twig()->render($template, $context);
    }

    public function response(string $template, array $context = [], int $status = 200, array $headers = []): ResponseInterface
    {
        return new Response(
            $status,
            $headers + ['Content-Type' => 'text/html; charset=utf-8'],
            $this->render($template, $context),
        );
    }

    /**
     * 同梱アセットの URL。内容のハッシュを付けるので、更新するとブラウザのキャッシュが使われなくなる。
     */
    public static function assetUrl(string $file): string
    {
        $path = self::ASSETS_DIR . '/' . $file;

        return '/assets/' . rawurlencode($file) . (is_file($path) ? '?v=' . substr(md5_file($path), 0, 10) : '');
    }

    private function twig(): Environment
    {
        if ($this->twig !== null) {
            return $this->twig;
        }

        $paths = [__DIR__ . '/../../templates'];
        if ($this->overrideDir !== null && $this->overrideDir !== '') {
            if (!is_dir($this->overrideDir)) {
                throw new \RuntimeException("Template directory not found: {$this->overrideDir}");
            }
            array_unshift($paths, $this->overrideDir);
        }

        $twig = new Environment(new FilesystemLoader($paths), [
            'autoescape' => 'html',
            'strict_variables' => true,
            'cache' => false,
        ]);
        foreach ($this->globals as $name => $value) {
            $twig->addGlobal($name, $value);
        }
        $twig->addFunction(new TwigFunction('asset', self::assetUrl(...)));

        return $this->twig = $twig;
    }
}
