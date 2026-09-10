<?php
// Template rendering. Author: Goh Jian Yu, Ooi Kean Wei, Ng Jing Siang, Khor Zhi Hong, Ivan Lim Tze Yang

namespace App\Core;

use RuntimeException;
use Throwable;

final class View
{
    private function __construct()
    {
    }

    public static function render(string $template, array $data = [], ?string $layout = 'layout'): string
    {
        $content = self::capture($template, $data);

        if ($layout === null) {
            return $content;
        }

        return self::capture($layout, $data + ['content' => $content]);
    }

    private static function capture(string $template, array $data): string
    {
        if (preg_match('#^[A-Za-z0-9_-]+$#', $template) !== 1) {
            throw new RuntimeException('Invalid view name.');
        }

        $file = dirname(__DIR__) . '/views/' . $template . '.php';

        if (!is_file($file)) {
            throw new RuntimeException('View not found: ' . $template);
        }

        extract($data, EXTR_SKIP);

        ob_start();

        try {
            require $file;
        } catch (Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }
}
