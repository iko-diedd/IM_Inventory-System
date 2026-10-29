<?php
declare(strict_types=1);

final class Security
{
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function csrfToken(): string
    {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    public static function verifyCsrf(): void
    {
        $posted = $_POST['csrf_token'] ?? '';
        $expected = $_SESSION['csrf_token'] ?? '';
        if (!is_string($posted) || !is_string($expected) || $expected === '' || !hash_equals($expected, $posted)) {
            http_response_code(400);
            throw new RuntimeException('The form expired or could not be verified. Reload the page and try again.');
        }
    }

    public static function requestKey(): string
    {
        $bytes = bin2hex(random_bytes(16));
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($bytes, 0, 8),
            substr($bytes, 8, 4),
            substr($bytes, 12, 4),
            substr($bytes, 16, 4),
            substr($bytes, 20, 12)
        );
    }

    public static function csvCell(mixed $value): string
    {
        $text = (string) $value;
        if (preg_match('/^[\s]*[=+\-@]/u', $text) === 1) {
            $text = "'" . $text;
        }

        return $text;
    }
}
