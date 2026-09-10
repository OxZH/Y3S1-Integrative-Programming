<?php
// Base controller. Author: Goh Jian Yu, Ooi Kean Wei, Ng Jing Siang, Khor Zhi Hong, Ivan Lim Tze Yang

namespace App\Core;

use App\Security\Csrf;

abstract class Controller
{
    protected function isPost(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }

    // The method check stops a GET changing state, since a URL that writes can
    // be triggered by an <img src> or a prefetch. The token check then proves
    // the POST came from one of our own pages.
    protected function requirePostWithCsrf(): void
    {
        if (!$this->isPost()) {
            http_response_code(405);
            header('Allow: POST');
            exit('This action must be submitted as a POST request.');
        }

        Csrf::check($_POST);
    }

    protected function view(string $template, array $data = []): void
    {
        echo View::render($template, $data + [
            'flash'     => $this->takeFlash(),
            'csrfField' => Csrf::field(),
        ]);
    }

    protected function redirect(string $url): never
    {
        if (!headers_sent()) {
            header('Location: ' . $url);
        }

        exit;
    }

    protected function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    protected function takeFlash(): array
    {
        $messages = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);

        return is_array($messages) ? $messages : [];
    }

    protected function queryId(string $key = 'id'): ?string
    {
        $value = $_GET[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
