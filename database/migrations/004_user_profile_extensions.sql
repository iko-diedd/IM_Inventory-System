BEGIN;

ALTER TABLE app_profiles
    ADD COLUMN IF NOT EXISTS gender TEXT,
    ADD COLUMN IF NOT EXISTS hire_date DATE,
    ADD COLUMN IF NOT EXISTS emergency_contact_name TEXT,
    ADD COLUMN IF NOT EXISTS emergency_contact_phone TEXT;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'public.app_profiles'::regclass
          AND conname = 'app_profiles_gender_valid'
    ) THEN
        ALTER TABLE app_profiles
            ADD CONSTRAINT app_profiles_gender_valid
            CHECK (gender IS NULL OR gender IN ('female', 'male', 'other'));
    END IF;
END;
$$;

INSERT INTO schema_migrations (version) VALUES ('004_user_profile_extensions')
ON CONFLICT (version) DO NOTHING;

COMMIT;
