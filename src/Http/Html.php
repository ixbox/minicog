<?php

namespace MiniCog\Http;

final class Html
{
    public static function page(string $title, string $body): string
    {
        $title = htmlspecialchars($title);

        return <<<HTML
            <!DOCTYPE html>
            <html lang="ja">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>$title - MiniCog</title>
                <link rel="stylesheet" href="https://unpkg.com/sakura.css/css/sakura.css">
                <style>.error{color:#c00} label{display:block} label input:not([type=checkbox]){display:block;width:100%} table{font-size:.9em}</style>
            </head>
            <body>
            <main>
            $body
            </main>
            </body>
            </html>
            HTML;
    }
}
