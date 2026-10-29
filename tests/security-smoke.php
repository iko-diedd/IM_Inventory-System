<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Security.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

session_start();
$_SESSION['csrf_token'] = 'test-token';
$_POST['csrf_token'] = 'test-token';
Security::verifyCsrf();

$escaped = Security::e('<script>alert(1)</script>');
if ($escaped !== '&lt;script&gt;alert(1)&lt;/script&gt;') {
    throw new RuntimeException('HTML escaping check failed.');
}

if (Security::csvCell('=1+1') !== "'=1+1" || Security::csvCell('  @SUM(A1)') !== "'  @SUM(A1)") {
    throw new RuntimeException('CSV formula-injection escaping check failed.');
}

$_POST['csrf_token'] = 'incorrect-token';
try {
    Security::verifyCsrf();
    throw new RuntimeException('Invalid CSRF token was accepted.');
} catch (RuntimeException $exception) {
    if ($exception->getMessage() === 'Invalid CSRF token was accepted.') {
        throw $exception;
    }
}

echo "PASS CSRF match/rejection, HTML escaping, and CSV formula escaping.\n";
