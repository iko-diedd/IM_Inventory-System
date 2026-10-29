BEGIN;

ALTER TABLE sales
    ADD COLUMN IF NOT EXISTS discount_rate NUMERIC(5,2)
    CHECK (discount_rate IS NULL OR (discount_rate >= 0 AND discount_rate <= 100));

INSERT INTO schema_migrations (version)
VALUES ('006_sales_discount_percentage')
ON CONFLICT (version) DO NOTHING;

COMMIT;
