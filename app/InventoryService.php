<?php
declare(strict_types=1);

final class InventoryService
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function createPurchase(int $supplierId, array $lines, string $actorId, float $taxRate = 0.0): int
    {
        if ($lines === [] || count($lines) > 50) {
            throw new InvalidArgumentException('A purchase order must contain between one and 50 product lines.');
        }
        if (!is_finite($taxRate) || $taxRate < 0 || $taxRate > 100) {
            throw new InvalidArgumentException('The purchase tax rate must be between 0 and 100 percent.');
        }
        $validatedLines = [];
        $seenProducts = [];
        foreach ($lines as $line) {
            if (!is_array($line) || !isset($line['product_id'], $line['quantity'], $line['unit_cost'])
                || !is_int($line['product_id']) || !is_int($line['quantity'])
                || !is_numeric($line['unit_cost'])) {
                throw new InvalidArgumentException('Purchase order lines are invalid.');
            }
            $productId = $line['product_id'];
            $quantity = $line['quantity'];
            $unitCost = (float) $line['unit_cost'];
            if ($productId < 1 || $quantity < 1 || !is_finite($unitCost) || $unitCost < 0
                || $unitCost > 9999999999.99 || $quantity > (int) floor(999999999999.99 / max($unitCost, 0.01))) {
                throw new InvalidArgumentException('Purchase quantities and costs must be valid and within the supported monetary range.');
            }
            if (isset($seenProducts[$productId])) {
                throw new InvalidArgumentException('Each product may appear only once on a purchase order.');
            }
            $seenProducts[$productId] = true;
            $validatedLines[] = [
                'product_id' => $productId,
                'quantity' => $quantity,
                'unit_cost' => round($unitCost, 2),
            ];
        }

        return $this->transaction(function () use ($supplierId, $validatedLines, $actorId, $taxRate): int {
            $supplier = $this->db->prepare('SELECT supplier_id FROM suppliers WHERE supplier_id = :id AND is_active');
            $supplier->execute(['id' => $supplierId]);
            if (!$supplier->fetchColumn()) {
                throw new RuntimeException('Select an active supplier.');
            }
            $total = 0.0;
            foreach ($validatedLines as $line) {
                $product = $this->db->prepare('SELECT product_id FROM products WHERE product_id = :id AND is_active');
                $product->execute(['id' => $line['product_id']]);
                if (!$product->fetchColumn()) {
                    throw new RuntimeException('Select an active product for every purchase line.');
                }
                $total += $line['quantity'] * $line['unit_cost'];
                if (!is_finite($total) || $total > 999999999999.99) {
                    throw new InvalidArgumentException('Purchase order total exceeds the supported monetary range.');
                }
            }

            $subtotal = round($total, 2);
            $tax = round($subtotal * $taxRate / 100, 2);
            $total = round($subtotal + $tax, 2);
            if (!is_finite($total) || $total > 999999999999.99) {
                throw new InvalidArgumentException('Purchase order total exceeds the supported monetary range.');
            }
            $number = 'PO-' . gmdate('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $insert = $this->db->prepare(
                'INSERT INTO purchases (purchase_number, supplier_id, created_by, total_amount, tax_amount)
                 VALUES (:number, :supplier, :actor, :total, :tax) RETURNING purchase_id'
            );
            $insert->execute([
                'number' => $number,
                'supplier' => $supplierId,
                'actor' => $actorId,
                'total' => $total,
                'tax' => $tax,
            ]);
            $purchaseId = (int) $insert->fetchColumn();
            $item = $this->db->prepare(
                'INSERT INTO purchase_items (purchase_id, product_id, ordered_quantity, unit_cost)
                 VALUES (:purchase, :product, :quantity, :cost)'
            );
            $supplierProduct = $this->db->prepare(
                'INSERT INTO supplier_products (supplier_id, product_id, last_unit_cost)
                 VALUES (:supplier, :product, :cost)
                 ON CONFLICT (supplier_id, product_id) DO UPDATE SET last_unit_cost = EXCLUDED.last_unit_cost'
            );
            foreach ($validatedLines as $line) {
                $item->execute([
                    'purchase' => $purchaseId,
                    'product' => $line['product_id'],
                    'quantity' => $line['quantity'],
                    'cost' => $line['unit_cost'],
                ]);
                $supplierProduct->execute([
                    'supplier' => $supplierId,
                    'product' => $line['product_id'],
                    'cost' => $line['unit_cost'],
                ]);
            }
            $this->audit($actorId, 'purchase.created', 'purchase', (string) $purchaseId, [
                'purchase_number' => $number,
                'line_count' => count($validatedLines),
                'subtotal' => $subtotal,
                'tax_rate' => $taxRate,
                'tax' => $tax,
                'total' => $total,
            ]);

            return $purchaseId;
        });
    }

    public function receivePurchase(int $purchaseId, int $purchaseItemId, int $quantity, string $requestKey, string $actorId): bool
    {
        $this->validateRequestKey($requestKey);
        if ($quantity < 1) {
            throw new InvalidArgumentException('Received quantity must be at least one.');
        }

        return $this->transaction(function () use ($purchaseId, $purchaseItemId, $quantity, $requestKey, $actorId): bool {
            $duplicate = $this->db->prepare('SELECT 1 FROM purchase_receipts WHERE idempotency_key = :key');
            $duplicate->execute(['key' => $requestKey]);
            if ($duplicate->fetchColumn()) {
                return false;
            }

            $purchaseQuery = $this->db->prepare('SELECT * FROM purchases WHERE purchase_id = :id FOR UPDATE');
            $purchaseQuery->execute(['id' => $purchaseId]);
            $purchase = $purchaseQuery->fetch();
            $duplicate->execute(['key' => $requestKey]);
            if ($duplicate->fetchColumn()) {
                return false;
            }
            if (!$purchase || in_array($purchase['status'], ['received', 'cancelled'], true)) {
                throw new RuntimeException('This purchase cannot receive additional stock.');
            }

            $itemQuery = $this->db->prepare(
                'SELECT * FROM purchase_items WHERE purchase_item_id = :item AND purchase_id = :purchase FOR UPDATE'
            );
            $itemQuery->execute(['item' => $purchaseItemId, 'purchase' => $purchaseId]);
            $item = $itemQuery->fetch();
            if (!$item || $quantity > (int) $item['ordered_quantity'] - (int) $item['received_quantity']) {
                throw new RuntimeException('Received quantity exceeds the outstanding quantity.');
            }

            $productQuery = $this->db->prepare('SELECT * FROM products WHERE product_id = :id FOR UPDATE');
            $productQuery->execute(['id' => $item['product_id']]);
            $product = $productQuery->fetch();
            if (!$product) {
                throw new RuntimeException('The purchase product no longer exists.');
            }

            $receipt = $this->db->prepare(
                'INSERT INTO purchase_receipts (purchase_id, idempotency_key, received_by)
                 VALUES (:purchase, :key, :actor)
                 ON CONFLICT (idempotency_key) DO NOTHING RETURNING receipt_id'
            );
            $receipt->execute(['purchase' => $purchaseId, 'key' => $requestKey, 'actor' => $actorId]);
            $receiptId = $receipt->fetchColumn();
            if (!$receiptId) {
                return false;
            }
            $receiptId = (int) $receiptId;
            $this->db->prepare(
                'INSERT INTO purchase_receipt_items (receipt_id, purchase_item_id, quantity, unit_cost)
                 VALUES (:receipt, :item, :quantity, :cost)'
            )->execute([
                'receipt' => $receiptId,
                'item' => $purchaseItemId,
                'quantity' => $quantity,
                'cost' => $item['unit_cost'],
            ]);

            $oldStock = (int) $product['stock_quantity'];
            $newStock = $oldStock + $quantity;
            $newAverage = $newStock === 0
                ? (float) $item['unit_cost']
                : (($oldStock * (float) $product['average_cost']) + ($quantity * (float) $item['unit_cost'])) / $newStock;
            $this->db->prepare(
                'UPDATE products SET stock_quantity = :stock, average_cost = :cost, updated_at = now() WHERE product_id = :id'
            )->execute(['stock' => $newStock, 'cost' => round($newAverage, 2), 'id' => $product['product_id']]);
            $this->db->prepare(
                'UPDATE purchase_items SET received_quantity = received_quantity + :quantity WHERE purchase_item_id = :id'
            )->execute(['quantity' => $quantity, 'id' => $purchaseItemId]);

            $movement = $this->db->prepare(
                "INSERT INTO inventory_movements
                 (product_id, transaction_type, quantity_change, previous_stock, new_stock, source_type, source_id, responsible_user, reason)
                 VALUES (:product, 'purchase_receipt', :change, :previous, :new, 'purchase_receipt', :source, :actor, :reason)"
            );
            $movement->execute([
                'product' => $product['product_id'],
                'change' => $quantity,
                'previous' => $oldStock,
                'new' => $newStock,
                'source' => $receiptId,
                'actor' => $actorId,
                'reason' => 'Confirmed supplier delivery',
            ]);

            $remaining = $this->db->prepare(
                'SELECT EXISTS (SELECT 1 FROM purchase_items WHERE purchase_id = :purchase
                 AND received_quantity < ordered_quantity) AS outstanding'
            );
            $remaining->execute(['purchase' => $purchaseId]);
            $status = filter_var($remaining->fetchColumn(), FILTER_VALIDATE_BOOL) ? 'partially_received' : 'received';
            $this->db->prepare('UPDATE purchases SET status = :status, updated_at = now() WHERE purchase_id = :id')
                ->execute(['status' => $status, 'id' => $purchaseId]);
            $this->audit($actorId, 'purchase.received', 'purchase', (string) $purchaseId, [
                'receipt_id' => $receiptId,
                'quantity' => $quantity,
            ]);

            return true;
        });
    }

    public function checkout(array $items, float $discountRate, float $taxRate, string $paymentMethod, string $requestKey, string $actorId): int
    {
        $this->validateRequestKey($requestKey);
        if (!in_array($paymentMethod, ['cash', 'card', 'bank_transfer', 'other'], true)
            || !is_finite($discountRate) || $discountRate < 0 || $discountRate > 100
            || !is_finite($taxRate) || $taxRate < 0 || $taxRate > 100) {
            throw new InvalidArgumentException('Review the payment method, discount percentage, and tax.');
        }
        $quantities = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException('Cart contains an invalid line item.');
            }
            $id = filter_var($item['product_id'] ?? null, FILTER_VALIDATE_INT);
            $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT);
            if (!$id || !$quantity || $quantity < 1) {
                throw new InvalidArgumentException('Cart contains an invalid product or quantity.');
            }
            if ($quantity > PHP_INT_MAX - ($quantities[$id] ?? 0)) {
                throw new InvalidArgumentException('Cart quantity exceeds the supported range.');
            }
            $quantities[$id] = ($quantities[$id] ?? 0) + $quantity;
        }
        if ($quantities === [] || count($quantities) > 40) {
            throw new InvalidArgumentException('Add between 1 and 40 distinct products to the cart.');
        }
        ksort($quantities);

        return $this->transaction(function () use ($quantities, $discountRate, $taxRate, $paymentMethod, $requestKey, $actorId): int {
            $duplicate = $this->db->prepare('SELECT sale_id FROM sales WHERE idempotency_key = :key');
            $duplicate->execute(['key' => $requestKey]);
            $existing = $duplicate->fetchColumn();
            if ($existing) {
                return (int) $existing;
            }

            $locked = [];
            $subtotal = 0.0;
            foreach ($quantities as $productId => $quantity) {
                $query = $this->db->prepare('SELECT *, CASE WHEN is_active THEN 1 ELSE 0 END AS active_int FROM products WHERE product_id = :id FOR UPDATE');
                $query->execute(['id' => $productId]);
                $product = $query->fetch();
                if (!$product || !(int) $product['active_int']) {
                    throw new RuntimeException('A cart product is missing or deactivated.');
                }
                $grossTotal = round((float) $product['selling_price'] * $quantity, 2);
                $subtotal += $grossTotal;
                $locked[] = ['product' => $product, 'quantity' => $quantity, 'gross_total' => $grossTotal];
            }
            $duplicate->execute(['key' => $requestKey]);
            $existing = $duplicate->fetchColumn();
            if ($existing) {
                return (int) $existing;
            }
            foreach ($locked as $line) {
                if ((int) $line['product']['stock_quantity'] < (int) $line['quantity']) {
                    throw new RuntimeException('Insufficient stock for ' . $line['product']['name'] . '. Available: ' . $line['product']['stock_quantity'] . '.');
                }
            }
            $subtotal = round($subtotal, 2);
            if (!is_finite($subtotal) || $subtotal > 999999999999.99) {
                throw new InvalidArgumentException('Sale subtotal exceeds the supported monetary range.');
            }
            $discount = self::calculateDiscountAmount($subtotal, $discountRate);
            $tax = round(($subtotal - $discount) * $taxRate / 100, 2);
            if (!is_finite($discount) || !is_finite($tax) || $subtotal - $discount + $tax > 999999999999.99) {
                throw new InvalidArgumentException('Sale total exceeds the supported monetary range.');
            }
            $remainingDiscount = round($discount, 2);
            foreach ($locked as $index => &$line) {
                $lineDiscount = $index === array_key_last($locked)
                    ? $remainingDiscount
                    : min($remainingDiscount, round($discount * $line['gross_total'] / max($subtotal, 0.01), 2));
                $line['discount'] = $lineDiscount;
                $line['line_total'] = round($line['gross_total'] - $lineDiscount, 2);
                $remainingDiscount = round($remainingDiscount - $lineDiscount, 2);
            }
            unset($line);

            $receiptNumber = 'RC-' . gmdate('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $sale = $this->db->prepare(
                'INSERT INTO sales
                    (receipt_number, idempotency_key, cashier_id, subtotal, discount, discount_rate, tax, total, payment_method)
                 VALUES (:receipt, :key, :cashier, :subtotal, :discount, :discount_rate, :tax, :total, :payment)
                 ON CONFLICT (idempotency_key) DO NOTHING
                 RETURNING sale_id'
            );
            $sale->execute([
                'receipt' => $receiptNumber,
                'key' => $requestKey,
                'cashier' => $actorId,
                'subtotal' => $subtotal,
                'discount' => round($discount, 2),
                'discount_rate' => round($discountRate, 2),
                'tax' => round($tax, 2),
                'total' => round($subtotal - $discount + $tax, 2),
                'payment' => $paymentMethod,
            ]);
            $saleId = $sale->fetchColumn();
            if (!$saleId) {
                $duplicate->execute(['key' => $requestKey]);
                return (int) $duplicate->fetchColumn();
            }
            $saleId = (int) $saleId;

            foreach ($locked as $line) {
                $product = $line['product'];
                $quantity = (int) $line['quantity'];
                $oldStock = (int) $product['stock_quantity'];
                $newStock = $oldStock - $quantity;
                $this->db->prepare('UPDATE products SET stock_quantity = :stock, updated_at = now() WHERE product_id = :id')
                    ->execute(['stock' => $newStock, 'id' => $product['product_id']]);
                $this->db->prepare(
                    'INSERT INTO sale_items
                        (sale_id, product_id, quantity, unit_price, unit_cost, discount, line_total, warranty_months)
                     VALUES (:sale, :product, :quantity, :price, :cost, :discount, :total, :warranty_months)'
                )->execute([
                    'sale' => $saleId,
                    'product' => $product['product_id'],
                    'quantity' => $quantity,
                    'price' => $product['selling_price'],
                    'cost' => $product['average_cost'],
                    'discount' => $line['discount'],
                    'total' => $line['line_total'],
                    'warranty_months' => $product['warranty_months'],
                ]);
                $this->db->prepare(
                    "INSERT INTO inventory_movements
                     (product_id, transaction_type, quantity_change, previous_stock, new_stock, source_type, source_id, responsible_user, reason)
                     VALUES (:product, 'sale', :change, :previous, :new, 'sale', :source, :actor, 'Point-of-sale checkout')"
                )->execute([
                    'product' => $product['product_id'],
                    'change' => -$quantity,
                    'previous' => $oldStock,
                    'new' => $newStock,
                    'source' => $saleId,
                    'actor' => $actorId,
                ]);
            }
            $this->audit($actorId, 'sale.completed', 'sale', (string) $saleId, [
                'receipt_number' => $receiptNumber,
                'discount_rate' => round($discountRate, 2),
                'discount' => round($discount, 2),
                'total' => round($subtotal - $discount + $tax, 2),
            ]);

            return $saleId;
        });
    }

    public static function calculateDiscountAmount(float $eligibleSubtotal, float $discountRate): float
    {
        if (!is_finite($eligibleSubtotal) || $eligibleSubtotal < 0
            || !is_finite($discountRate) || $discountRate < 0 || $discountRate > 100) {
            throw new InvalidArgumentException('Subtotal and discount percentage must be valid.');
        }

        return round($eligibleSubtotal * $discountRate / 100, 2);
    }

    public function adjustStock(int $productId, int $countedStock, string $reason, string $actorId): void
    {
        if ($countedStock < 0 || trim($reason) === '') {
            throw new InvalidArgumentException('Enter a valid counted stock quantity and a reason.');
        }

        $this->transaction(function () use ($productId, $countedStock, $reason, $actorId): void {
            $query = $this->db->prepare('SELECT * FROM products WHERE product_id = :id FOR UPDATE');
            $query->execute(['id' => $productId]);
            $product = $query->fetch();
            if (!$product) {
                throw new RuntimeException('Product not found.');
            }
            $oldStock = (int) $product['stock_quantity'];
            $change = $countedStock - $oldStock;
            if ($change === 0) {
                throw new RuntimeException('Counted stock matches the current balance; no adjustment was needed.');
            }
            $newStock = $countedStock;
            $this->db->prepare('UPDATE products SET stock_quantity = :stock, updated_at = now() WHERE product_id = :id')
                ->execute(['stock' => $newStock, 'id' => $productId]);
            $this->db->prepare(
                "INSERT INTO inventory_movements
                 (product_id, transaction_type, quantity_change, previous_stock, new_stock, source_type, source_id, responsible_user, reason)
                 VALUES (:product, 'adjustment', :change, :previous, :new, 'manual', :source, :actor, :reason)"
            )->execute([
                'product' => $productId,
                'change' => $change,
                'previous' => $oldStock,
                'new' => $newStock,
                'source' => $productId,
                'actor' => $actorId,
                'reason' => trim($reason),
            ]);
            $this->audit($actorId, 'inventory.adjusted', 'product', (string) $productId, [
                'change' => $change,
                'previous_stock' => $oldStock,
                'new_stock' => $newStock,
                'reason' => trim($reason),
            ]);
        });
    }

    /** Suggests quantities that restore stock to the minimum while considering open purchase orders. */
    public function reorderSuggestions(): array
    {
        return $this->db->query(
            "SELECT p.product_id, p.sku, p.name, p.stock_quantity, p.minimum_stock,
                    GREATEST(p.minimum_stock - p.stock_quantity - COALESCE(open_orders.quantity, 0), 0) AS suggested_quantity,
                    supplier.supplier_id, supplier.supplier_name,
                    COALESCE(supplier.last_unit_cost, p.average_cost) AS unit_cost
             FROM products p
             LEFT JOIN LATERAL (
                 SELECT SUM(pi.ordered_quantity - pi.received_quantity) AS quantity
                 FROM purchase_items pi
                 JOIN purchases pu USING (purchase_id)
                 WHERE pi.product_id = p.product_id AND pu.status IN ('ordered', 'partially_received')
             ) open_orders ON true
             LEFT JOIN LATERAL (
                 SELECT s.supplier_id, s.name AS supplier_name, sp.last_unit_cost
                 FROM supplier_products sp
                 JOIN suppliers s USING (supplier_id)
                 WHERE sp.product_id = p.product_id AND s.is_active
                 ORDER BY sp.last_unit_cost NULLS LAST, s.name
                 LIMIT 1
             ) supplier ON true
             WHERE p.is_active AND p.stock_quantity <= p.minimum_stock
             ORDER BY p.name"
        )->fetchAll();
    }

    /** Creates one purchase order per supplier for uncovered low-stock products. */
    public function autoReorder(string $actorId, float $taxRate = 0.0): array
    {
        $bySupplier = [];
        $skipped = 0;
        foreach ($this->reorderSuggestions() as $row) {
            $quantity = (int) $row['suggested_quantity'];
            if ($quantity < 1) {
                continue;
            }
            if ($row['supplier_id'] === null) {
                $skipped++;
                continue;
            }
            $bySupplier[(int) $row['supplier_id']][] = [
                'product_id' => (int) $row['product_id'],
                'quantity' => $quantity,
                'unit_cost' => (float) $row['unit_cost'],
            ];
        }

        $orders = 0;
        $items = 0;
        foreach ($bySupplier as $supplierId => $lines) {
            $purchaseId = $this->createPurchase($supplierId, $lines, $actorId, $taxRate);
            $this->audit($actorId, 'inventory.auto_reorder', 'purchase', (string) $purchaseId, [
                'line_count' => count($lines),
            ]);
            $orders++;
            $items += count($lines);
        }

        return ['orders' => $orders, 'items' => $items, 'skipped' => $skipped];
    }

    public function requestReturn(
        int $saleId,
        int $saleItemId,
        int $quantity,
        string $condition,
        string $reason,
        string $requestKey,
        string $actorId
    ): int {
        $this->validateRequestKey($requestKey);
        if ($quantity < 1 || trim($reason) === '' || !in_array($condition, ['resalable', 'defective', 'damaged', 'pending_inspection'], true)) {
            throw new InvalidArgumentException('Provide a valid return quantity, condition, and reason.');
        }

        return $this->transaction(function () use ($saleId, $saleItemId, $quantity, $condition, $reason, $requestKey, $actorId): int {
            $duplicate = $this->db->prepare('SELECT return_id FROM returns WHERE idempotency_key = :key');
            $duplicate->execute(['key' => $requestKey]);
            $existing = $duplicate->fetchColumn();
            if ($existing) {
                return (int) $existing;
            }
            $saleQuery = $this->db->prepare('SELECT sale_id FROM sales WHERE sale_id = :id AND status = :status FOR UPDATE');
            $saleQuery->execute(['id' => $saleId, 'status' => 'completed']);
            if (!$saleQuery->fetchColumn()) {
                throw new RuntimeException('Only completed sales can be returned.');
            }
            $duplicate->execute(['key' => $requestKey]);
            $existing = $duplicate->fetchColumn();
            if ($existing) {
                return (int) $existing;
            }
            $itemQuery = $this->db->prepare(
                'SELECT * FROM sale_items WHERE sale_item_id = :item AND sale_id = :sale FOR UPDATE'
            );
            $itemQuery->execute(['item' => $saleItemId, 'sale' => $saleId]);
            $saleItem = $itemQuery->fetch();
            if (!$saleItem) {
                throw new RuntimeException('The selected item is not part of this sale.');
            }
            $returnedQuery = $this->db->prepare(
                "SELECT COALESCE(sum(ri.quantity), 0) AS returned_quantity,
                        COALESCE(sum(ri.refund_amount), 0) AS reserved_refund,
                        (SELECT count(*) FROM warranty_claims wc
                         WHERE wc.sale_item_id = :claim_item AND wc.status <> 'rejected') AS claimed_quantity
                 FROM return_items ri JOIN returns r ON r.return_id = ri.return_id
                 WHERE ri.sale_item_id = :item AND r.status IN ('pending', 'approved')"
            );
            $returnedQuery->execute(['item' => $saleItemId, 'claim_item' => $saleItemId]);
            $reserved = $returnedQuery->fetch();
            $quantityRemainingAfterReturns = (int) $saleItem['quantity'] - (int) $reserved['returned_quantity'];
            $remainingQuantity = $quantityRemainingAfterReturns - (int) $reserved['claimed_quantity'];
            if ($quantity > $remainingQuantity) {
                throw new RuntimeException('Return quantity exceeds the unreturned and unclaimed sold quantity.');
            }

            $remainingRefund = round((float) $saleItem['line_total'] - (float) $reserved['reserved_refund'], 2);
            $refund = $quantity === $quantityRemainingAfterReturns && (int) $reserved['claimed_quantity'] === 0
                ? $remainingRefund
                : min($remainingRefund, round((float) $saleItem['line_total'] * $quantity / (int) $saleItem['quantity'], 2));
            $returnNumber = 'RET-' . gmdate('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $return = $this->db->prepare(
                'INSERT INTO returns (return_number, idempotency_key, sale_id, requested_by, reason, refund_amount)
                 VALUES (:number, :key, :sale, :actor, :reason, :refund) RETURNING return_id'
            );
            $return->execute([
                'number' => $returnNumber,
                'key' => $requestKey,
                'sale' => $saleId,
                'actor' => $actorId,
                'reason' => trim($reason),
                'refund' => $refund,
            ]);
            $returnId = (int) $return->fetchColumn();
            $this->db->prepare(
                'INSERT INTO return_items (return_id, sale_item_id, quantity, condition, inventory_disposition, refund_amount)
                 VALUES (:return, :item, :quantity, :condition, :disposition, :refund)'
            )->execute([
                'return' => $returnId,
                'item' => $saleItemId,
                'quantity' => $quantity,
                'condition' => $condition,
                'disposition' => $condition === 'resalable' ? 'restock' : 'do_not_restock',
                'refund' => $refund,
            ]);
            $this->audit($actorId, 'return.requested', 'return', (string) $returnId, ['refund' => $refund]);

            return $returnId;
        });
    }

    public function requestWarrantyClaim(int $saleItemId, string $serialNumber, string $issue, string $actorId): int
    {
        $serialNumber = trim($serialNumber);
        $issue = trim($issue);
        if ($saleItemId < 1 || $serialNumber === '' || strlen($serialNumber) > 120
            || $issue === '' || strlen($issue) > 2000) {
            throw new InvalidArgumentException('Enter a valid sold item, serial number, and warranty issue.');
        }

        return $this->transaction(function () use ($saleItemId, $serialNumber, $issue, $actorId): int {
            $soldItem = $this->db->prepare(
                "SELECT si.quantity, si.warranty_months, s.cashier_id, s.status AS sale_status,
                        s.created_at + (si.warranty_months * INTERVAL '1 month') AS expires_at,
                        actor.role AS actor_role,
                        (s.created_at + (si.warranty_months * INTERVAL '1 month') >= now()) AS in_warranty
                 FROM sale_items si JOIN sales s USING (sale_id)
                 JOIN app_profiles actor ON actor.user_id = :actor AND actor.is_active
                 WHERE si.sale_item_id = :item
                 FOR UPDATE OF si"
            );
            $soldItem->execute(['actor' => $actorId, 'item' => $saleItemId]);
            $item = $soldItem->fetch();
            if (!$item || $item['sale_status'] !== 'completed') {
                throw new RuntimeException('Select an item from a completed sale.');
            }
            if ($item['cashier_id'] !== $actorId && !in_array($item['actor_role'], ['administrator', 'manager'], true)) {
                throw new RuntimeException('You can only submit a claim for a sale assigned to your account.');
            }
            if ($item['warranty_months'] === null || (int) $item['warranty_months'] < 1
                || !in_array($item['in_warranty'], [true, 't', '1', 1], true)) {
                throw new RuntimeException('The product has no active warranty for this sale.');
            }

            $count = $this->db->prepare(
                "SELECT
                    (SELECT count(*) FROM warranty_claims WHERE sale_item_id = :claim_item AND status <> 'rejected')
                    + (SELECT COALESCE(sum(ri.quantity),0) FROM return_items ri
                       JOIN returns r USING (return_id)
                       WHERE ri.sale_item_id = :return_item AND r.status IN ('pending', 'approved'))"
            );
            $count->execute(['claim_item' => $saleItemId, 'return_item' => $saleItemId]);
            if ((int) $count->fetchColumn() >= (int) $item['quantity']) {
                throw new RuntimeException('All units on this sale item already have an active or approved warranty claim.');
            }

            $claimNumber = 'WC-' . gmdate('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $insert = $this->db->prepare(
                'INSERT INTO warranty_claims
                    (claim_number, sale_item_id, serial_number, issue_description, warranty_expires_at, requested_by)
                 VALUES (:number, :item, :serial, :issue, CAST(:expires AS date), :actor)
                 RETURNING warranty_claim_id'
            );
            $insert->execute([
                'number' => $claimNumber,
                'item' => $saleItemId,
                'serial' => $serialNumber,
                'issue' => $issue,
                'expires' => $item['expires_at'],
                'actor' => $actorId,
            ]);
            $claimId = (int) $insert->fetchColumn();
            $this->audit($actorId, 'warranty_claim.requested', 'warranty_claim', (string) $claimId, [
                'claim_number' => $claimNumber,
                'serial_number' => $serialNumber,
                'sale_item_id' => $saleItemId,
            ]);

            return $claimId;
        });
    }

    public function decideWarrantyClaim(int $claimId, bool $approve, string $resolution, string $actorId): void
    {
        $resolution = trim($resolution);
        if ($claimId < 1 || $resolution === '' || strlen($resolution) > 2000) {
            throw new InvalidArgumentException('Enter a decision note of up to 2,000 characters.');
        }

        $this->transaction(function () use ($claimId, $approve, $resolution, $actorId): void {
            $actor = $this->db->prepare(
                "SELECT role FROM app_profiles WHERE user_id = :actor AND is_active AND role IN ('administrator', 'manager')"
            );
            $actor->execute(['actor' => $actorId]);
            if (!$actor->fetchColumn()) {
                throw new RuntimeException('Only an active administrator or manager can decide warranty claims.');
            }
            $query = $this->db->prepare('SELECT * FROM warranty_claims WHERE warranty_claim_id = :id FOR UPDATE');
            $query->execute(['id' => $claimId]);
            $claim = $query->fetch();
            if (!$claim || $claim['status'] !== 'pending') {
                throw new RuntimeException('This warranty claim is not awaiting a decision.');
            }
            $this->db->prepare(
                'UPDATE warranty_claims SET status = :status, decided_by = :actor,
                    resolution = :resolution, decided_at = now() WHERE warranty_claim_id = :id'
            )->execute([
                'status' => $approve ? 'approved' : 'rejected',
                'actor' => $actorId,
                'resolution' => $resolution,
                'id' => $claimId,
            ]);
            $this->audit($actorId, $approve ? 'warranty_claim.approved' : 'warranty_claim.rejected',
                'warranty_claim', (string) $claimId, ['claim_number' => $claim['claim_number'], 'resolution' => $resolution]);
        });
    }

    public function decideReturn(int $returnId, bool $approve, string $actorId): void
    {
        $this->transaction(function () use ($returnId, $approve, $actorId): void {
            $query = $this->db->prepare('SELECT * FROM returns WHERE return_id = :id FOR UPDATE');
            $query->execute(['id' => $returnId]);
            $return = $query->fetch();
            if (!$return || $return['status'] !== 'pending') {
                throw new RuntimeException('This return is not awaiting a decision.');
            }
            $this->db->prepare(
                'UPDATE returns SET status = :status, approved_by = :actor, decided_at = now() WHERE return_id = :id'
            )->execute([
                'status' => $approve ? 'approved' : 'rejected',
                'actor' => $actorId,
                'id' => $returnId,
            ]);

            if ($approve) {
                $items = $this->db->prepare(
                    'SELECT ri.*, si.product_id, si.unit_cost FROM return_items ri
                     JOIN sale_items si ON si.sale_item_id = ri.sale_item_id
                     WHERE ri.return_id = :id ORDER BY si.product_id FOR UPDATE OF si'
                );
                $items->execute(['id' => $returnId]);
                foreach ($items->fetchAll() as $item) {
                    if ($item['condition'] !== 'resalable' || $item['inventory_disposition'] !== 'restock') {
                        continue;
                    }
                    $productQuery = $this->db->prepare('SELECT * FROM products WHERE product_id = :id FOR UPDATE');
                    $productQuery->execute(['id' => $item['product_id']]);
                    $product = $productQuery->fetch();
                    if (!$product) {
                        throw new RuntimeException('Return product no longer exists.');
                    }
                    $oldStock = (int) $product['stock_quantity'];
                    $quantity = (int) $item['quantity'];
                    $newStock = $oldStock + $quantity;
                    $newAverage = $newStock === 0
                        ? (float) $item['unit_cost']
                        : (($oldStock * (float) $product['average_cost']) + ($quantity * (float) $item['unit_cost'])) / $newStock;
                    $this->db->prepare(
                        'UPDATE products SET stock_quantity = :stock, average_cost = :cost, updated_at = now() WHERE product_id = :id'
                    )->execute([
                        'stock' => $newStock,
                        'cost' => round($newAverage, 2),
                        'id' => $product['product_id'],
                    ]);
                    $this->db->prepare(
                        "INSERT INTO inventory_movements
                         (product_id, transaction_type, quantity_change, previous_stock, new_stock, source_type, source_id, responsible_user, reason)
                         VALUES (:product, 'return', :change, :previous, :new, 'return', :source, :actor, 'Approved resalable return')"
                    )->execute([
                        'product' => $product['product_id'],
                        'change' => $quantity,
                        'previous' => $oldStock,
                        'new' => $newStock,
                        'source' => $returnId,
                        'actor' => $actorId,
                    ]);
                }
            }
            $this->audit($actorId, $approve ? 'return.approved' : 'return.rejected', 'return', (string) $returnId, []);
        });
    }

    private function transaction(callable $operation): mixed
    {
        $this->db->beginTransaction();
        try {
            $result = $operation();
            $this->db->commit();
            return $result;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    private function audit(string $actorId, string $action, string $entity, string $entityId, array $details): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO audit_logs (actor_id, action, entity_type, entity_id, details)
             VALUES (:actor, :action, :entity, :id, CAST(:details AS jsonb))'
        );
        $statement->execute([
            'actor' => $actorId,
            'action' => $action,
            'entity' => $entity,
            'id' => $entityId,
            'details' => json_encode($details, JSON_THROW_ON_ERROR),
        ]);
    }

    private function validateRequestKey(string $requestKey): void
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $requestKey)) {
            throw new InvalidArgumentException('Request identifier is invalid. Reload the form and try again.');
        }
    }
}
