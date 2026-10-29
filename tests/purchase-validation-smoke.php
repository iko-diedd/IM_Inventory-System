<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/InventoryService.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

final class ValidationOnlyPDO extends PDO
{
    public function __construct()
    {
    }
}

$service = new InventoryService(new ValidationOnlyPDO());
$invalidInputs = [
    [],
    array_fill(0, 51, ['product_id' => 1, 'quantity' => 1, 'unit_cost' => 1]),
    [['product_id' => 1, 'quantity' => 1]],
    [
        ['product_id' => 1, 'quantity' => 1, 'unit_cost' => 1],
        ['product_id' => 1, 'quantity' => 2, 'unit_cost' => 2],
    ],
    [['product_id' => 1, 'quantity' => 1, 'unit_cost' => -1]],
    [['product_id' => 1, 'quantity' => 999999999999999, 'unit_cost' => 1]],
    [['product_id' => 1, 'quantity' => 101, 'unit_cost' => 9999999999.99]],
];

foreach ($invalidInputs as $lines) {
    try {
        $service->createPurchase(1, $lines, '00000000-0000-0000-0000-000000000001');
        throw new RuntimeException('Invalid purchase order input was accepted.');
    } catch (InvalidArgumentException $exception) {
        if ($exception->getMessage() === 'Invalid purchase order input was accepted.') {
            throw $exception;
        }
    }
}

$invalidClaims = [
    static fn() => $service->requestWarrantyClaim(0, 'SERIAL-1', 'No power', '00000000-0000-0000-0000-000000000001'),
    static fn() => $service->requestWarrantyClaim(1, '', 'No power', '00000000-0000-0000-0000-000000000001'),
    static fn() => $service->requestWarrantyClaim(1, str_repeat('S', 121), 'No power', '00000000-0000-0000-0000-000000000001'),
    static fn() => $service->requestWarrantyClaim(1, 'SERIAL-1', '', '00000000-0000-0000-0000-000000000001'),
    static fn() => $service->decideWarrantyClaim(0, true, 'Reviewed', '00000000-0000-0000-0000-000000000001'),
    static fn() => $service->decideWarrantyClaim(1, true, '', '00000000-0000-0000-0000-000000000001'),
];

foreach ($invalidClaims as $invalidClaim) {
    try {
        $invalidClaim();
        throw new RuntimeException('Invalid warranty claim input was accepted.');
    } catch (InvalidArgumentException $exception) {
        if ($exception->getMessage() === 'Invalid warranty claim input was accepted.') {
            throw $exception;
        }
    }
}

echo 'PASS purchase line and warranty claim input validation.' . PHP_EOL;
