<?php

declare(strict_types=1);

if (!function_exists('__')) {
    /**
     * Translation helper.
     *
     * Note: The heavy lifting is done by App\Services\I18nService. This helper is used
     * mostly by templates, where the container is not easily available.
     *
     * Supports sprintf format for placeholders (%s, %d, %1$s, etc.)
     */
    function __(string $key, array $params = [], ?string $locale = null): string
    {
        $basePath = dirname(__DIR__);
        $langDir = $basePath . '/lang';

        $candidate = $locale ?? ($_COOKIE['locale'] ?? null);

        if ($candidate === null) {
            $accept = strtolower($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
            if (str_starts_with($accept, 'pt')) {
                $candidate = 'pt-BR';
            } elseif (str_starts_with($accept, 'fr')) {
                $candidate = 'fr-FR';
            } else {
                $candidate = 'en';
            }
        }

        if (!in_array($candidate, ['en', 'pt-BR', 'fr-FR'], true)) {
            $candidate = 'en';
        }

        $file = $langDir . '/' . $candidate . '.php';
        $fallback = $langDir . '/en.php';

        /** @var array<string,string> $translations */
        $translations = file_exists($file) ? require $file : require $fallback;
        $text = $translations[$key] ?? null;
        if ($text === null) {
            /** @var array<string,string> $fallbackTranslations */
            $fallbackTranslations = require $fallback;
            $text = $fallbackTranslations[$key] ?? $key;
        }

        if (!empty($params)) {
            $text = sprintf($text, ...array_values($params));
        }

        return $text;
    }
}

if (!function_exists('trans')) {
    function trans(string $key, array $params = [], ?string $locale = null): string
    {
        return __($key, $params, $locale);
    }
}

if (!function_exists('trans_choice')) {
    /**
     * Pluralization helper.
     *
     * Supports format: "singular|plural" with sprintf placeholders.
     * Example: "%d active user|%d active users"
     */
    function trans_choice(string $key, int $count, array $params = [], ?string $locale = null): string
    {
        $text = __($key, [], $locale);

        if (str_contains($text, '|')) {
            $parts = explode('|', $text);
            $text = $count === 1 ? $parts[0] : ($parts[1] ?? $parts[0]);
        }

        $allParams = array_merge([$count], array_values($params));

        return sprintf($text, ...$allParams);
    }
}

if (!function_exists('session_start_safe')) {
    function session_start_safe(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }
}

if (!function_exists('flash')) {
    function flash(string $key, ?string $value = null): ?string
    {
        session_start_safe();

        if ($value !== null) {
            $_SESSION[$key] = $value;
            return null;
        }

        $val = $_SESSION[$key] ?? null;
        if (array_key_exists($key, $_SESSION)) {
            unset($_SESSION[$key]);
        }
        return is_string($val) ? $val : null;
    }
}

if (!function_exists('session_get')) {
    function session_get(string $key, mixed $default = null): mixed
    {
        session_start_safe();
        return $_SESSION[$key] ?? $default;
    }
}

if (!function_exists('session_set')) {
    function session_set(string $key, mixed $value): void
    {
        session_start_safe();
        $_SESSION[$key] = $value;
    }
}

if (!function_exists('session_remove')) {
    function session_remove(string $key): void
    {
        session_start_safe();
        unset($_SESSION[$key]);
    }
}

if (!function_exists('session_destroy_safe')) {
    function session_destroy_safe(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
            session_destroy();
        }
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        session_start_safe();
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['_csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
    }
}

if (!function_exists('csrf_verify')) {
    function csrf_verify(string $submitted): bool
    {
        $token = csrf_token();
        return $token !== '' && hash_equals($token, $submitted);
    }
}

if (!function_exists('redirect')) {
    function redirect(string $path): \Nyholm\Psr7\Response
    {
        return new \Nyholm\Psr7\Response(302, ['Location' => $path]);
    }
}

