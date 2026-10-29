BEGIN;

ALTER TABLE app_profiles
    ADD COLUMN IF NOT EXISTS email TEXT,
    ADD COLUMN IF NOT EXISTS address TEXT,
    ADD COLUMN IF NOT EXISTS birth_date DATE,
    ADD COLUMN IF NOT EXISTS phone TEXT,
    ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMPTZ;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'public.app_profiles'::regclass
          AND conname = 'app_profiles_email_nonempty'
    ) THEN
        ALTER TABLE app_profiles
            ADD CONSTRAINT app_profiles_email_nonempty
            CHECK (email IS NULL OR length(trim(email)) > 0);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'public.app_profiles'::regclass
          AND conname IN ('app_profiles_birth_date_check', 'app_profiles_birth_date_valid')
    ) THEN
        ALTER TABLE app_profiles
            ADD CONSTRAINT app_profiles_birth_date_valid
            CHECK (birth_date IS NULL OR birth_date <= CURRENT_DATE);
    END IF;
END;
$$;

CREATE UNIQUE INDEX IF NOT EXISTS app_profiles_email_ci_idx
    ON app_profiles (lower(email)) WHERE email IS NOT NULL;

INSERT INTO schema_migrations (version) VALUES ('003_user_management')
ON CONFLICT (version) DO NOTHING;

COMMIT;
