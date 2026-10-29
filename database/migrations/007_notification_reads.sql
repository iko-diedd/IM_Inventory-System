BEGIN;

CREATE TABLE IF NOT EXISTS app_notification_reads (
    user_id UUID NOT NULL REFERENCES app_profiles(user_id) ON DELETE CASCADE,
    notification_key TEXT NOT NULL CHECK (length(notification_key) BETWEEN 1 AND 300),
    read_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (user_id, notification_key)
);

ALTER TABLE app_notification_reads ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS app_notification_reads_trusted_backend ON app_notification_reads;
CREATE POLICY app_notification_reads_trusted_backend ON app_notification_reads
    FOR ALL TO inventory_app
    USING (true)
    WITH CHECK (true);

REVOKE ALL ON app_notification_reads FROM PUBLIC, anon, authenticated, service_role;
GRANT SELECT, INSERT ON app_notification_reads TO inventory_app;

INSERT INTO schema_migrations (version)
VALUES ('007_notification_reads')
ON CONFLICT (version) DO NOTHING;

COMMIT;
