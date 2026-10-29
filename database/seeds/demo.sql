BEGIN;

INSERT INTO categories (name, description) VALUES
('Processors', 'Desktop and workstation CPUs'),
('Graphics Cards', 'Discrete GPUs and graphics adapters'),
('Motherboards', 'Desktop and workstation boards'),
('Memory', 'System memory modules'),
('Storage', 'SSD and hard disk drives'),
('Power Supplies', 'Computer power supplies'),
('Computer Cases', 'Chassis and enclosures'),
('Cooling', 'CPU and case cooling'),
('Monitors', 'Displays and monitors'),
('Peripherals', 'Keyboards, mice, and accessories'),
('Networking', 'Network adapters and equipment'),
('Cables and Accessories', 'Cables and general accessories')
ON CONFLICT (name) DO NOTHING;

INSERT INTO brands (name) VALUES
('Northstar Components'), ('Pinecone Labs'), ('Copperfield Systems'), ('Summit Electronics')
ON CONFLICT (name) DO NOTHING;

INSERT INTO suppliers (name, contact_person, email, phone, address) VALUES
('Example Hardware Wholesale', 'Demo Contact', 'demo@example.invalid', '+1-555-0100', '100 Sample Road'),
('Fictional Tech Supply', 'Sample Buyer', 'orders@example.invalid', '+1-555-0101', '200 Example Avenue')
ON CONFLICT DO NOTHING;

INSERT INTO products (sku, name, description, category_id, brand_id, model, selling_price, minimum_stock, warranty_months, specifications)
SELECT seed.sku, seed.name, seed.description, c.category_id, b.brand_id, seed.model,
       seed.selling_price, seed.minimum_stock, seed.warranty_months, seed.specifications::jsonb
FROM (VALUES
    ('DEMO-CPU-8C-001', '8-Core Desktop Processor', 'Fictional demo CPU for inventory workflows', 'Processors', 'Northstar Components', 'NS-8C-100', 189.99::numeric, 4, 36, '{"socket":"AM5","cores":8,"base_clock":"3.6 GHz"}'),
    ('DEMO-MEM-16G-001', '16 GB DDR5 Memory Kit', 'Fictional demo memory kit', 'Memory', 'Pinecone Labs', 'PL-DDR5-16', 54.99::numeric, 6, 60, '{"capacity":"16 GB","interface":"DDR5","speed":"5600 MT/s"}'),
    ('DEMO-SSD-1T-001', '1 TB NVMe Solid State Drive', 'Fictional demo storage device', 'Storage', 'Copperfield Systems', 'CS-NV1-1T', 79.99::numeric, 5, 36, '{"capacity":"1 TB","interface":"PCIe 4.0"}'),
    ('DEMO-PSU-650-001', '650 W Modular Power Supply', 'Fictional demo power supply', 'Power Supplies', 'Summit Electronics', 'SE-650M', 89.99::numeric, 3, 60, '{"wattage":"650 W","efficiency":"80 Plus Gold"}')
) AS seed(sku, name, description, category_name, brand_name, model, selling_price, minimum_stock, warranty_months, specifications)
JOIN categories c ON c.name = seed.category_name
JOIN brands b ON b.name = seed.brand_name
ON CONFLICT (sku) DO NOTHING;

INSERT INTO supplier_products (supplier_id, product_id, last_unit_cost)
SELECT s.supplier_id, p.product_id, CASE p.sku
    WHEN 'DEMO-CPU-8C-001' THEN 120.00
    WHEN 'DEMO-MEM-16G-001' THEN 31.00
    WHEN 'DEMO-SSD-1T-001' THEN 46.00
    ELSE 58.00
END
FROM suppliers s CROSS JOIN products p
WHERE s.name = 'Example Hardware Wholesale' AND p.sku LIKE 'DEMO-%'
ON CONFLICT (supplier_id, product_id) DO NOTHING;

DO $$
DECLARE
    actor UUID;
    supplier BIGINT;
    product BIGINT;
    purchase BIGINT;
    purchase_item BIGINT;
    receipt BIGINT;
    sale BIGINT;
    previous_stock BIGINT;
    current_cost NUMERIC(12,2);
    current_price NUMERIC(12,2);
    current_warranty_months INTEGER;
    new_stock BIGINT;
BEGIN
    SELECT user_id INTO actor FROM app_profiles WHERE is_active ORDER BY created_at LIMIT 1;
    SELECT supplier_id INTO supplier FROM suppliers WHERE name = 'Example Hardware Wholesale' ORDER BY supplier_id LIMIT 1;
    SELECT product_id, stock_quantity, average_cost, selling_price, warranty_months
      INTO product, previous_stock, current_cost, current_price, current_warranty_months
      FROM products WHERE sku = 'DEMO-CPU-8C-001';

    IF actor IS NULL OR supplier IS NULL OR product IS NULL THEN
        RAISE NOTICE 'Demo purchase and sale transactions skipped: create an active application profile and run this seed again.';
        RETURN;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM purchases WHERE purchase_number = 'DEMO-PO-001') THEN
        INSERT INTO purchases (purchase_number, supplier_id, created_by, status, total_amount, notes)
        VALUES ('DEMO-PO-001', supplier, actor, 'received', 1200.00, 'Fictional seed purchase') RETURNING purchase_id INTO purchase;
        INSERT INTO purchase_items (purchase_id, product_id, ordered_quantity, received_quantity, unit_cost)
        VALUES (purchase, product, 10, 10, 120.00) RETURNING purchase_item_id INTO purchase_item;
        INSERT INTO purchase_receipts (purchase_id, idempotency_key, received_by)
        VALUES (purchase, '6f84a3d8-9d83-48a4-a9f1-0c1df93d0021', actor) RETURNING receipt_id INTO receipt;
        INSERT INTO purchase_receipt_items (receipt_id, purchase_item_id, quantity, unit_cost)
        VALUES (receipt, purchase_item, 10, 120.00);
        new_stock := previous_stock + 10;
        UPDATE products
           SET stock_quantity = new_stock,
               average_cost = round((previous_stock * current_cost + 10 * 120.00) / new_stock, 2),
               updated_at = now()
         WHERE product_id = product;
        INSERT INTO inventory_movements
            (product_id, transaction_type, quantity_change, previous_stock, new_stock, source_type, source_id, responsible_user, reason)
        VALUES (product, 'purchase_receipt', 10, previous_stock, new_stock, 'purchase_receipt', receipt, actor, 'Fictional demo receiving');
        INSERT INTO audit_logs (actor_id, action, entity_type, entity_id, details)
        VALUES (actor, 'purchase.received', 'purchase', purchase::text, '{"demo":true}'::jsonb);
        previous_stock := new_stock;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM sales WHERE receipt_number = 'DEMO-RC-001') THEN
        SELECT stock_quantity, average_cost INTO previous_stock, current_cost
        FROM products WHERE product_id = product FOR UPDATE;
        IF previous_stock >= 2 THEN
            INSERT INTO sales (receipt_number, idempotency_key, cashier_id, subtotal, discount, tax, total, payment_method)
            VALUES ('DEMO-RC-001', '6957903c-2a15-4f4d-a64e-4cb119c8c878', actor, current_price * 2, 0, 0, current_price * 2, 'cash')
            RETURNING sale_id INTO sale;
            INSERT INTO sale_items
                (sale_id, product_id, quantity, unit_price, unit_cost, discount, line_total, warranty_months)
            VALUES (sale, product, 2, current_price, current_cost, 0, current_price * 2, current_warranty_months);
            new_stock := previous_stock - 2;
            UPDATE products SET stock_quantity = new_stock, updated_at = now() WHERE product_id = product;
            INSERT INTO inventory_movements
                (product_id, transaction_type, quantity_change, previous_stock, new_stock, source_type, source_id, responsible_user, reason)
            VALUES (product, 'sale', -2, previous_stock, new_stock, 'sale', sale, actor, 'Fictional demo checkout');
            INSERT INTO audit_logs (actor_id, action, entity_type, entity_id, details)
            VALUES (actor, 'sale.completed', 'sale', sale::text, '{"demo":true}'::jsonb);
        END IF;
    END IF;
END;
$$;

COMMIT;
