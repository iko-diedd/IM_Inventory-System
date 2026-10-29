<?php
declare(strict_types=1);

final class Auth
{
    public static function login(string $email, string $password): void
    {
        $url = self::baseUrl();
        $key = (string) getenv('SUPABASE_ANON_KEY');
        if ($key === '') {
            throw new RuntimeException('Supabase Auth is not configured. Set SUPABASE_ANON_KEY in .env.');
        }

        $result = self::request($url . '/auth/v1/token?grant_type=password', $key, [
            'email' => $email,
            'password' => $password,
        ]);
        if (empty($result['access_token']) || empty($result['user']['id'])) {
            throw new RuntimeException('Supabase Auth returned an incomplete login response.');
        }

        session_regenerate_id(true);
        $_SESSION['auth'] = [
            'access_token' => $result['access_token'],
            'refresh_token' => $result['refresh_token'] ?? '',
            'expires_at' => time() + (int) ($result['expires_in'] ?? 3600),
            'created_at' => time(),
            'identity' => $result['user'],
        ];
    }

    public static function currentUser(PDO $db): ?array
    {
        if (empty($_SESSION['auth']['access_token'])) {
            return null;
        }

        $auth = $_SESSION['auth'];
        if (($auth['created_at'] ?? 0) < time() - 28800) {
            self::logoutWithNotice('Your session expired. Sign in again.');
            return null;
        }
        $url = self::baseUrl();
        $key = (string) getenv('SUPABASE_ANON_KEY');
        if ($key === '') {
            throw new RuntimeException('Supabase Auth is not configured. Set SUPABASE_ANON_KEY in .env.');
        }

        if (($auth['expires_at'] ?? 0) <= time() + 30 && !empty($auth['refresh_token'])) {
            try {
                $refreshed = self::request($url . '/auth/v1/token?grant_type=refresh_token', $key, [
                    'refresh_token' => $auth['refresh_token'],
                ]);
            } catch (RuntimeException $exception) {
                if (!in_array($exception->getCode(), [400, 401, 403], true)) {
                    throw $exception;
                }
                self::logoutWithNotice('Your session expired. Sign in again.');
                return null;
            }
            $_SESSION['auth']['access_token'] = $refreshed['access_token'] ?? '';
            $_SESSION['auth']['refresh_token'] = $refreshed['refresh_token'] ?? $auth['refresh_token'];
            $_SESSION['auth']['expires_at'] = time() + (int) ($refreshed['expires_in'] ?? 3600);
            $auth = $_SESSION['auth'];
        }

        try {
            $identity = self::request($url . '/auth/v1/user', $key, null, $auth['access_token']);
        } catch (RuntimeException $exception) {
            if (!in_array($exception->getCode(), [401, 403], true)) {
                throw $exception;
            }
            self::logoutWithNotice('Your session is no longer valid. Sign in again.');
            return null;
        }
        if (empty($identity['id'])) {
            self::logoutWithNotice('No active application profile is assigned to this account. Contact an administrator.');
            return null;
        }

        $statement = $db->prepare(
            'SELECT user_id, full_name, role, is_active FROM app_profiles WHERE user_id = :user_id'
        );
        $statement->execute(['user_id' => $identity['id']]);
        $profile = $statement->fetch();
        $isActive = $profile && in_array($profile['is_active'], [true, 't', '1', 1], true);
        if (!$profile || !$isActive) {
            self::logout();
            return null;
        }

        return [
            'id' => $profile['user_id'],
            'name' => $profile['full_name'],
            'role' => $profile['role'],
            'email' => $identity['email'] ?? '',
        ];
    }

    public static function createManagedUser(string $email, string $password, string $fullName): array
    {
        return self::adminRequest('POST', '/auth/v1/admin/users', [
            'email' => $email,
            'password' => $password,
            'email_confirm' => true,
            'user_metadata' => ['full_name' => $fullName],
        ]);
    }

    public static function updateManagedUser(string $userId, array $changes): array
    {
        return self::adminRequest('PUT', '/auth/v1/admin/users/' . rawurlencode($userId), $changes);
    }

    public static function deleteManagedUser(string $userId): void
    {
        self::adminRequest('DELETE', '/auth/v1/admin/users/' . rawurlencode($userId));
    }

    public static function logout(): void
    {
        unset($_SESSION['auth']);
        session_regenerate_id(true);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    private static function logoutWithNotice(string $notice): void
    {
        self::logout();
        $_SESSION['auth_notice'] = $notice;
    }

    private static function adminRequest(string $method, string $path, ?array $body = null): array
    {
        $key = (string) getenv('SUPABASE_SERVICE_ROLE_KEY');
        if ($key === '') {
            throw new RuntimeException('User management is not configured. Set SUPABASE_SERVICE_ROLE_KEY in the server environment.');
        }
        if (!self::isServiceRoleJwt($key)) {
            throw new RuntimeException(
                'SUPABASE_SERVICE_ROLE_KEY must be the legacy service_role JWT from Supabase API settings, not the anon, publishable, or sb_secret API key.'
            );
        }

        return self::request(self::baseUrl() . $path, $key, $body, $key, $method);
    }

    private static function isServiceRoleJwt(string $key): bool
    {
        $parts = explode('.', $key);
        if (count($parts) !== 3 || $parts[1] === '') {
            return false;
        }

        $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);
        if ($payload === false) {
            return false;
        }
        $claims = json_decode($payload, true);

        return is_array($claims) && ($claims['role'] ?? null) === 'service_role';
    }

    private static function request(
        string $url,
        string $apiKey,
        ?array $body,
        ?string $bearer = null,
        ?string $method = null
    ): array
    {
        $headers = [
            'apikey: ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        if ($bearer !== null) {
            $headers[] = 'Authorization: Bearer ' . $bearer;
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method ?? ($body === null ? 'GET' : 'POST'),
                'header' => implode("\r\n", $headers),
                'content' => $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR),
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
                'timeout' => 12,
            ],
        ]);
        $warning = '';
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }
            $warning = $message;
            return true;
        });
        try {
            $response = file_get_contents($url, false, $context);
        } finally {
            restore_error_handler();
        }
        if ($response === false) {
            if ($warning !== '') {
                error_log('Supabase Auth request failed: ' . $warning);
            }
            throw new RuntimeException('Could not reach Supabase Auth. Check SUPABASE_URL and network access.');
        }

        $statusLine = $http_response_header[0] ?? '';
        preg_match('/\s(\d{3})\s/', $statusLine, $matches);
        $status = isset($matches[1]) ? (int) $matches[1] : 0;
        $decoded = $response === '' ? [] : json_decode($response, true);
        if ($status < 200 || $status >= 300) {
            $message = is_array($decoded) ? ($decoded['msg'] ?? $decoded['message'] ?? $decoded['error_description'] ?? 'Authentication failed.') : 'Authentication failed.';
            throw new RuntimeException((string) $message, $status);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Supabase Auth returned an invalid response.');
        }

        return $decoded;
    }

    private static function baseUrl(): string
    {
        $url = rtrim((string) getenv('SUPABASE_URL'), '/');
        $parts = parse_url($url);
        if ($url === '' || !is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || isset($parts['path']) && $parts['path'] !== '') {
            throw new RuntimeException('SUPABASE_URL must be an HTTPS project URL copied from the Supabase Dashboard.');
        }

        return $url;
    }
}
