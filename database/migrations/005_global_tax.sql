BEGIN;

ALTER TABLE purchases
    ADD COLUMN IF NOT EXISTS tax_amount NUMERIC(14,2) NOT NULL DEFAULT 0 CHECK (tax_amount >= 0);

CREATE TABLE IF NOT EXISTS app_settings (
    setting_key TEXT PRIMARY KEY,
    setting_value NUMERIC(7,4) NOT NULL CHECK (setting_value >= 0 AND setting_value <= 100),
    updated_by UUID REFERENCES app_profiles(user_id) ON DELETE SET NULL,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

INSERT INTO app_settings (setting_key, setting_value)
VALUES ('tax_rate', 0)
ON CONFLICT (setting_key) DO NOTHING;

ALTER TABLE app_settings ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS app_settings_trusted_backend ON app_settings;
CREATE POLICY app_settings_trusted_backend ON app_settings FOR ALL TO inventory_app
USING (true) WITH CHECK (true);

REVOKE ALL ON app_settings FROM PUBLIC, anon, authenticated, service_role;
GRANT SELECT, INSERT, UPDATE ON app_settings TO inventory_app;

CREATE OR REPLACE FUNCTION protect_purchase_header() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' OR OLD.status IN ('received', 'cancelled')
       OR ROW(NEW.purchase_number, NEW.supplier_id, NEW.created_by, NEW.total_amount, NEW.tax_amount, NEW.notes, NEW.created_at)
          IS DISTINCT FROM
          ROW(OLD.purchase_number, OLD.supplier_id, OLD.created_by, OLD.total_amount, OLD.tax_amount, OLD.notes, OLD.created_at) THEN
        RAISE EXCEPTION 'Purchase history cannot be rewritten';
    END IF;
    RETURN NEW;
END;
$$;

INSERT INTO schema_migrations (version) VALUES ('005_global_tax')
ON CONFLICT (version) DO NOTHING;

COMMIT;
