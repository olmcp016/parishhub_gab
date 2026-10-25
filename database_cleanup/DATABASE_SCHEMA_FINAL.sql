-- =============================================================================
-- ParishHub — FINAL VERIFIED DATABASE SCHEMA (PostgreSQL / Supabase)
-- Generated: 2026-10-25 by the database cleanup audit.
--
-- WHAT THIS IS
--   The effective schema produced by replaying database/supabase_schema.sql and
--   every migration in database/ (git order, corrected where noted) into a
--   throwaway PostgreSQL 16 instance, with cleanup_audit.sql applied, plus
--   DATABASE_MIGRATION_SAFE.sql Part A. It is generated from the database
--   catalog itself (pg_catalog), not hand-written, so table/column names, types,
--   defaults, constraints, indexes, functions and triggers are exact.
--
-- *** DO NOT RUN THIS AGAINST THE PRODUCTION DATABASE. ***
--   It is the reference definition for documentation (Chapter 2 / ERD), code
--   review, and building a fresh development/test database. To bring the live
--   database in line, use DATABASE_PREFLIGHT_CHECK.sql + DATABASE_MIGRATION_SAFE.sql.
--
-- NOT INCLUDED: opt-in Parts B (FK hardening), C (archive unused tables) and
--   D (Row Level Security) of the safe migration — see DATABASE_CLEANUP_PLAN.md.
--   Supabase-managed schemas (auth, storage, extensions) are out of scope.
--
-- Tables: 34   Enums: 11   Indexes (non-constraint): 33   Triggers: 10
-- =============================================================================

SET client_min_messages = warning;

-- -----------------------------------------------------------------------------
-- 1. ENUM TYPES
-- -----------------------------------------------------------------------------
DO $$ BEGIN
    CREATE TYPE public.announcement_status AS ENUM ('draft', 'published', 'archived');
EXCEPTION WHEN duplicate_object THEN NULL; END $$;
DO $$ BEGIN
    CREATE TYPE public.chat_sender_type AS ENUM ('user', 'bot');
EXCEPTION WHEN duplicate_object THEN NULL; END $$;
DO $$ BEGIN
    CREATE TYPE public.marital_status_type AS ENUM ('Single', 'Married', 'Widowed', 'Separated');
EXCEPTION WHEN duplicate_object THEN NULL; END $$;
DO $$ BEGIN
    CREATE TYPE public.mass_intention_type AS ENUM ('Living', 'Dead', 'Thanksgiving', 'Healing', 'Birthday');
EXCEPTION WHEN duplicate_object THEN NULL; END $$;
DO $$ BEGIN
    CREATE TYPE public.notification_category AS ENUM ('appointment', 'payment', 'announcement', 'system');
EXCEPTION WHEN duplicate_object THEN NULL; END $$;
DO $$ BEGIN
    CREATE TYPE public.notification_type AS ENUM ('email', 'sms', 'website');
EXCEPTION WHEN duplicate_object THEN NULL; END $$;
DO $$ BEGIN
    CREATE TYPE public.payment_status_type AS ENUM ('pending', 'verified', 'failed', 'refunded', 'cancelled');
EXCEPTION WHEN duplicate_object THEN NULL; END $$;
DO $$ BEGIN
    CREATE TYPE public.priest_status AS ENUM ('active', 'on_leave', 'inactive');
EXCEPTION WHEN duplicate_object THEN NULL; END $$;
DO $$ BEGIN
    CREATE TYPE public.service_category AS ENUM ('Mass Intention', 'Wedding', 'Baptism', 'Funeral', 'Blessing', 'Confirmation', 'First Communion', 'Certificate', 'Donation', 'Wake', 'Anointing');
EXCEPTION WHEN duplicate_object THEN NULL; END $$;
DO $$ BEGIN
    CREATE TYPE public.user_gender AS ENUM ('Male', 'Female');
EXCEPTION WHEN duplicate_object THEN NULL; END $$;
DO $$ BEGIN
    CREATE TYPE public.user_status AS ENUM ('active', 'inactive', 'suspended');
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

-- -----------------------------------------------------------------------------
-- 2. TABLES (primary keys, unique and check constraints inline; foreign keys in §3)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.activity_logs (
    log_id                       SERIAL,
    user_id                      integer,
    action                       character varying(150) NOT NULL,
    module                       character varying(100),
    ip_address                   character varying(45),
    user_agent                   character varying(255),
    created_at                   timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT activity_logs_pkey PRIMARY KEY (log_id)
);

CREATE TABLE IF NOT EXISTS public.announcements (
    announcement_id              SERIAL,
    title                        character varying(200) NOT NULL,
    content                      text NOT NULL,
    image                        character varying(255) DEFAULT NULL::character varying,
    image2                       character varying(255) DEFAULT NULL::character varying,
    image3                       character varying(255) DEFAULT NULL::character varying,
    posted_by                    integer NOT NULL,
    is_pinned                    boolean DEFAULT false,
    category                     character varying(30) DEFAULT NULL::character varying,
    status                       announcement_status DEFAULT 'published'::announcement_status,
    start_date                   date,
    end_date                     date,
    duration_type                character varying(20) DEFAULT NULL::character varying,
    created_at                   timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at                   timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT announcements_pkey PRIMARY KEY (announcement_id),
    CONSTRAINT announcements_duration_type_check CHECK (((duration_type)::text = ANY ((ARRAY['specific_date'::character varying, 'month'::character varying, 'year'::character varying, 'custom_range'::character varying])::text[])))
);

CREATE TABLE IF NOT EXISTS public.app_sessions (
    session_id                   character varying(128) NOT NULL,
    session_data                 text NOT NULL,
    last_activity                timestamp with time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at                   timestamp with time zone NOT NULL,
    CONSTRAINT app_sessions_pkey PRIMARY KEY (session_id)
);

CREATE TABLE IF NOT EXISTS public.appointment_drafts (
    draft_id                     BIGSERIAL,
    service_type                 character varying(20) NOT NULL,
    parishioner_id               integer,
    guest_access_token_hash      character(64),
    guest_name                   character varying(255),
    guest_email                  character varying(255),
    guest_phone                  character varying(30),
    service_id                   integer NOT NULL,
    priest_id                    integer,
    appointment_date             date,
    appointment_time             time without time zone,
    schedule_type                character varying(20),
    pss_claim                    character varying(20),
    sponsor_count                integer,
    wedding_sponsor_count        integer,
    remarks                      text,
    contact_phone                character varying(30),
    location_address             text,
    status                       character varying(20) NOT NULL DEFAULT 'draft'::character varying,
    created_at                   timestamp with time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                   timestamp with time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at                   timestamp with time zone NOT NULL DEFAULT (CURRENT_TIMESTAMP + '24:00:00'::interval),
    finalized_appointment_id     integer,
    precana_type                 character varying(10) DEFAULT NULL::character varying,
    precana_date                 date,
    precana_time                 time without time zone,
    precana_status               character varying(20) DEFAULT NULL::character varying,
    outside_priest_name          text,
    CONSTRAINT appointment_drafts_pkey PRIMARY KEY (draft_id),
    CONSTRAINT appointment_drafts_token_unique UNIQUE (guest_access_token_hash),
    CONSTRAINT appointment_drafts_finalized_state_check CHECK (((((status)::text = 'finalized'::text) AND (finalized_appointment_id IS NOT NULL)) OR (((status)::text <> 'finalized'::text) AND (finalized_appointment_id IS NULL)))),
    CONSTRAINT appointment_drafts_guest_identity_check CHECK (((parishioner_id IS NOT NULL) OR ((guest_name IS NOT NULL) AND (guest_phone IS NOT NULL)))),
    CONSTRAINT appointment_drafts_owner_check CHECK ((((parishioner_id IS NOT NULL) AND (guest_access_token_hash IS NULL)) OR ((parishioner_id IS NULL) AND (guest_access_token_hash IS NOT NULL)))),
    CONSTRAINT appointment_drafts_precana_complete_check CHECK (((precana_type IS NULL) OR ((precana_date IS NOT NULL) AND (precana_time IS NOT NULL) AND (precana_status IS NOT NULL)))),
    CONSTRAINT appointment_drafts_precana_status_check CHECK (((precana_status IS NULL) OR ((precana_status)::text = ANY ((ARRAY['scheduled'::character varying, 'pending_confirmation'::character varying, 'proposed'::character varying, 'confirmed'::character varying])::text[])))),
    CONSTRAINT appointment_drafts_precana_type_check CHECK (((precana_type IS NULL) OR ((precana_type)::text = ANY ((ARRAY['Regular'::character varying, 'Special'::character varying])::text[])))),
    CONSTRAINT appointment_drafts_pss_claim_check CHECK (((pss_claim IS NULL) OR ((pss_claim)::text = ANY ((ARRAY['pss'::character varying, 'non_pss'::character varying])::text[])))),
    CONSTRAINT appointment_drafts_schedule_type_check CHECK (((schedule_type IS NULL) OR ((schedule_type)::text = ANY ((ARRAY['Regular'::character varying, 'Special'::character varying])::text[])))),
    CONSTRAINT appointment_drafts_service_type_check CHECK (((service_type)::text = ANY ((ARRAY['Wedding'::character varying, 'Baptism'::character varying])::text[]))),
    CONSTRAINT appointment_drafts_sponsor_count_check CHECK (((sponsor_count IS NULL) OR ((sponsor_count >= 0) AND (sponsor_count <= 100)))),
    CONSTRAINT appointment_drafts_status_check CHECK (((status)::text = ANY ((ARRAY['draft'::character varying, 'finalized'::character varying, 'expired'::character varying, 'abandoned'::character varying])::text[]))),
    CONSTRAINT appointment_drafts_wedding_sponsor_count_check CHECK (((wedding_sponsor_count IS NULL) OR ((wedding_sponsor_count >= 0) AND (wedding_sponsor_count <= 200))))
);

CREATE TABLE IF NOT EXISTS public.appointment_status (
    status_id                    SERIAL,
    status_name                  character varying(50) NOT NULL,
    CONSTRAINT appointment_status_pkey PRIMARY KEY (status_id),
    CONSTRAINT appointment_status_status_name_key UNIQUE (status_name)
);

CREATE TABLE IF NOT EXISTS public.appointments (
    appointment_id               SERIAL,
    parishioner_id               integer NOT NULL,
    service_id                   integer NOT NULL,
    priest_id                    integer,
    appointment_date             date,
    appointment_time             time without time zone,
    status_id                    integer NOT NULL DEFAULT 1,
    remarks                      text,
    approved_by                  integer,
    approved_at                  timestamp with time zone,
    confirmed_by                 integer,
    confirmed_at                 timestamp with time zone,
    cancelled_reason             character varying(255) DEFAULT NULL::character varying,
    date_of_death                date,
    schedule_type                character varying(10) DEFAULT NULL::character varying,
    guest_name                   character varying(150) DEFAULT NULL::character varying,
    guest_email                  character varying(150) DEFAULT NULL::character varying,
    guest_phone                  character varying(20) DEFAULT NULL::character varying,
    guest_reference              character varying(20) DEFAULT NULL::character varying,
    contact_phone                character varying(20) DEFAULT NULL::character varying,
    location_address             text,
    created_at                   timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at                   timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    rejection_reason             character varying(255) DEFAULT NULL::character varying,
    pss_classification           character varying(20),
    pss_claim                    character varying(20) DEFAULT NULL::character varying,
    sponsor_count                integer,
    wedding_sponsor_count        integer,
    fee_snapshot                 jsonb,
    pss_verified_by              integer,
    pss_verified_at              timestamp without time zone,
    requirements_snapshot        jsonb,
    requester_name               character varying(150) DEFAULT NULL::character varying,
    patient_name                 character varying(150) DEFAULT NULL::character varying,
    requirements_review_status   character varying(30) DEFAULT NULL::character varying,
    revision_requested_at        timestamp with time zone,
    precana_type                 character varying(10) DEFAULT NULL::character varying,
    precana_date                 date,
    precana_time                 time without time zone,
    precana_status               character varying(20) DEFAULT NULL::character varying,
    preferred_burial_date        date,
    preferred_burial_time        time without time zone,
    payment_deadline             timestamp with time zone,
    outside_priest_name          text,
    CONSTRAINT appointments_pkey PRIMARY KEY (appointment_id),
    CONSTRAINT appointments_guest_reference_key UNIQUE (guest_reference),
    CONSTRAINT appointments_precana_complete_check CHECK (((precana_type IS NULL) OR ((precana_date IS NOT NULL) AND (precana_time IS NOT NULL) AND (precana_status IS NOT NULL)))),
    CONSTRAINT appointments_precana_status_check CHECK (((precana_status IS NULL) OR ((precana_status)::text = ANY ((ARRAY['scheduled'::character varying, 'pending_confirmation'::character varying, 'proposed'::character varying, 'confirmed'::character varying])::text[])))),
    CONSTRAINT appointments_precana_type_check CHECK (((precana_type IS NULL) OR ((precana_type)::text = ANY ((ARRAY['Regular'::character varying, 'Special'::character varying])::text[])))),
    CONSTRAINT appointments_pss_claim_check CHECK (((pss_claim IS NULL) OR ((pss_claim)::text = ANY ((ARRAY['pss'::character varying, 'non_pss'::character varying])::text[])))),
    CONSTRAINT appointments_pss_classification_check CHECK (((pss_classification IS NULL) OR ((pss_classification)::text = ANY ((ARRAY['pending_verification'::character varying, 'pss'::character varying, 'non_pss'::character varying])::text[])))),
    CONSTRAINT appointments_requirements_review_status_check CHECK (((requirements_review_status IS NULL) OR ((requirements_review_status)::text = ANY ((ARRAY['awaiting_revision'::character varying, 'ready_for_rereview'::character varying])::text[])))),
    CONSTRAINT appointments_schedule_type_check CHECK (((schedule_type)::text = ANY ((ARRAY['Regular'::character varying, 'Special'::character varying])::text[]))),
    CONSTRAINT appointments_sponsor_count_check CHECK (((sponsor_count IS NULL) OR (sponsor_count >= 0))),
    CONSTRAINT appointments_wedding_sponsor_count_check CHECK (((wedding_sponsor_count IS NULL) OR (wedding_sponsor_count >= 0)))
);

CREATE TABLE IF NOT EXISTS public.auth_throttle (
    throttle_key                 character varying(190) NOT NULL,
    failures                     integer NOT NULL DEFAULT 0,
    locked_until                 timestamp with time zone,
    updated_at                   timestamp with time zone NOT NULL DEFAULT now(),
    CONSTRAINT auth_throttle_pkey PRIMARY KEY (throttle_key)
);

CREATE TABLE IF NOT EXISTS public.calendar (
    calendar_id                  SERIAL,
    title                        character varying(150) NOT NULL,
    calendar_date                date NOT NULL,
    is_blocked                   boolean DEFAULT false,
    notes                        character varying(255),
    created_by                   integer,
    created_at                   timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT calendar_pkey PRIMARY KEY (calendar_id)
);

CREATE TABLE IF NOT EXISTS public.chat_messages (
    message_id                   SERIAL,
    user_id                      integer,
    session_id                   character varying(100) NOT NULL,
    sender                       chat_sender_type NOT NULL,
    message                      text NOT NULL,
    intent                       character varying(100) DEFAULT NULL::character varying,
    created_at                   timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chat_messages_pkey PRIMARY KEY (message_id)
);

CREATE TABLE IF NOT EXISTS public.donations (
    donation_id                  SERIAL,
    appointment_id               integer NOT NULL,
    donor_name                   character varying(150),
    donor_email                  character varying(150),
    purpose                      character varying(50),
    message                      text,
    project_id                   integer,
    CONSTRAINT donations_pkey PRIMARY KEY (donation_id)
);

CREATE TABLE IF NOT EXISTS public.email_logs (
    email_log_id                 SERIAL,
    dedupe_key                   character varying(255) NOT NULL,
    notification_type            character varying(40) NOT NULL,
    appointment_id               integer,
    booking_reference            character varying(40) DEFAULT NULL::character varying,
    recipient                    character varying(150) NOT NULL,
    subject                      character varying(255) NOT NULL,
    body_html                    text NOT NULL,
    status                       character varying(20) NOT NULL DEFAULT 'pending'::character varying,
    attempts                     integer NOT NULL DEFAULT 0,
    failure_reason               text,
    last_attempt_at              timestamp with time zone,
    sent_at                      timestamp with time zone,
    created_at                   timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT email_logs_pkey PRIMARY KEY (email_log_id),
    CONSTRAINT email_logs_dedupe_key_key UNIQUE (dedupe_key),
    CONSTRAINT email_logs_status_check CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'sent'::character varying, 'failed'::character varying])::text[])))
);

CREATE TABLE IF NOT EXISTS public.events (
    event_id                     SERIAL,
    title                        character varying(150) NOT NULL,
    description                  text,
    event_date                   date NOT NULL,
    event_time                   time without time zone,
    location                     character varying(150),
    location_id                  integer,
    priest_id                    integer,
    created_by                   integer,
    created_at                   timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT events_pkey PRIMARY KEY (event_id)
);

CREATE TABLE IF NOT EXISTS public.generated_forms (
    generated_form_id            BIGSERIAL,
    service_category             character varying(20) NOT NULL,
    appointment_id               integer,
    draft_id                     bigint,
    form_type                    character varying(60) NOT NULL,
    form_data                    jsonb NOT NULL DEFAULT '{}'::jsonb,
    document_id                  integer,
    status                       character varying(30) NOT NULL DEFAULT 'draft'::character varying,
    rejection_reason             text,
    created_at                   timestamp with time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                   timestamp with time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT generated_forms_pkey PRIMARY KEY (generated_form_id),
    CONSTRAINT generated_forms_owner_check CHECK (((
CASE
    WHEN (appointment_id IS NOT NULL) THEN 1
    ELSE 0
END +
CASE
    WHEN (draft_id IS NOT NULL) THEN 1
    ELSE 0
END) = 1)),
    CONSTRAINT generated_forms_service_category_check CHECK (((service_category)::text = ANY ((ARRAY['Wedding'::character varying, 'Baptism'::character varying, 'Funeral'::character varying])::text[]))),
    CONSTRAINT generated_forms_status_check CHECK (((status)::text = ANY ((ARRAY['draft'::character varying, 'generated'::character varying, 'pending_review'::character varying, 'approved'::character varying, 'rejected'::character varying])::text[])))
);

CREATE TABLE IF NOT EXISTS public.issued_certificates (
    issued_certificate_id        BIGSERIAL,
    appointment_id               integer NOT NULL,
    source_appointment_id        integer,
    certificate_type             character varying(20) NOT NULL,
    book_no                      character varying(50) DEFAULT NULL::character varying,
    page_no                      character varying(50) DEFAULT NULL::character varying,
    line_no                      character varying(50) DEFAULT NULL::character varying,
    registry_date                date,
    certificate_purpose          character varying(150) DEFAULT NULL::character varying,
    form_data                    jsonb NOT NULL DEFAULT '{}'::jsonb,
    document_id                  integer,
    status                       character varying(20) NOT NULL DEFAULT 'draft'::character varying,
    issued_by                    integer,
    issued_at                    timestamp with time zone,
    created_at                   timestamp with time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                   timestamp with time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    claimed_at                   timestamp with time zone,
    claimed_by_user_id           bigint,
    CONSTRAINT issued_certificates_pkey PRIMARY KEY (issued_certificate_id),
    CONSTRAINT issued_certificates_certificate_type_check CHECK (((certificate_type)::text = ANY ((ARRAY['Baptism'::character varying, 'Marriage'::character varying, 'Confirmation'::character varying, 'Death'::character varying])::text[]))),
    CONSTRAINT issued_certificates_status_check CHECK (((status)::text = ANY ((ARRAY['draft'::character varying, 'generated'::character varying, 'ready_for_pickup'::character varying, 'claimed'::character varying])::text[])))
);

CREATE TABLE IF NOT EXISTS public.locations (
    location_id                  SERIAL,
    name                         character varying(150) NOT NULL,
    notes                        character varying(255),
    is_active                    boolean DEFAULT true,
    created_at                   timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    location_category            character varying(20) NOT NULL DEFAULT 'other'::character varying,
    CONSTRAINT locations_pkey PRIMARY KEY (location_id),
    CONSTRAINT locations_category_check CHECK (((location_category)::text = ANY ((ARRAY['barangay'::character varying, 'school'::character varying, 'chapel'::character varying, 'other'::character varying])::text[])))
);

CREATE TABLE IF NOT EXISTS public.mass_intentions (
    intention_id                 SERIAL,
    appointment_id               integer NOT NULL,
    intention_type               mass_intention_type NOT NULL,
    offerer_name                 character varying(150) NOT NULL,
    intention_for                character varying(255) NOT NULL,
    message                      text,
    CONSTRAINT mass_intentions_pkey PRIMARY KEY (intention_id)
);

CREATE TABLE IF NOT EXISTS public.notifications (
    notification_id              SERIAL,
    user_id                      integer NOT NULL,
    type                         notification_type NOT NULL DEFAULT 'website'::notification_type,
    category                     notification_category DEFAULT 'system'::notification_category,
    title                        character varying(150) NOT NULL,
    message                      text NOT NULL,
    is_read                      boolean DEFAULT false,
    created_at                   timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    appointment_id               integer,
    CONSTRAINT notifications_pkey PRIMARY KEY (notification_id)
);

CREATE TABLE IF NOT EXISTS public.official_receipts (
    receipt_id                   SERIAL,
    payment_id                   integer NOT NULL,
    receipt_number               character varying(50) NOT NULL,
    issued_by                    integer,
    issue_date                   timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT official_receipts_pkey PRIMARY KEY (receipt_id),
    CONSTRAINT official_receipts_payment_id_key UNIQUE (payment_id),
    CONSTRAINT official_receipts_receipt_number_key UNIQUE (receipt_number)
);

CREATE TABLE IF NOT EXISTS public.parishioners (
    parishioner_id               SERIAL,
    user_id                      integer NOT NULL,
    marital_status               marital_status_type DEFAULT 'Single'::marital_status_type,
    occupation                   character varying(100),
    baptism_date                 date,
    confirmation_date            date,
    CONSTRAINT parishioners_pkey PRIMARY KEY (parishioner_id),
    CONSTRAINT parishioners_user_id_key UNIQUE (user_id)
);
COMMENT ON COLUMN public.parishioners.baptism_date IS 'database_cleanup 2026-10-25: re-added (read by secretary/parishioner-detail.php)';
COMMENT ON COLUMN public.parishioners.confirmation_date IS 'database_cleanup 2026-10-25: re-added (read by secretary/parishioner-detail.php)';

CREATE TABLE IF NOT EXISTS public.password_reset_otps (
    id                           BIGSERIAL,
    email                        character varying(150) NOT NULL,
    otp_hash                     character varying(255) NOT NULL,
    expires_at                   timestamp with time zone NOT NULL,
    attempts                     smallint NOT NULL DEFAULT 0,
    created_at                   timestamp with time zone NOT NULL DEFAULT now(),
    CONSTRAINT password_reset_otps_pkey PRIMARY KEY (id),
    CONSTRAINT prot_one_per_email UNIQUE (email)
);

CREATE TABLE IF NOT EXISTS public.payment_methods (
    method_id                    SERIAL,
    method_name                  character varying(50) NOT NULL,
    CONSTRAINT payment_methods_pkey PRIMARY KEY (method_id),
    CONSTRAINT payment_methods_method_name_key UNIQUE (method_name)
);

CREATE TABLE IF NOT EXISTS public.payments (
    payment_id                   SERIAL,
    appointment_id               integer NOT NULL,
    reference_number             character varying(100),
    amount                       numeric(10,2) NOT NULL,
    method_id                    integer NOT NULL,
    payment_status               payment_status_type DEFAULT 'pending'::payment_status_type,
    payment_date                 timestamp with time zone,
    verified_by                  integer,
    verified_at                  timestamp with time zone,
    proof_of_payment             character varying(255) DEFAULT NULL::character varying,
    created_at                   timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT payments_pkey PRIMARY KEY (payment_id),
    CONSTRAINT payments_reference_number_key UNIQUE (reference_number)
);

CREATE TABLE IF NOT EXISTS public.priest_unavailability (
    unavailability_id            SERIAL,
    priest_id                    integer NOT NULL,
    unavailable_date             date NOT NULL,
    start_time                   time without time zone,
    end_time                     time without time zone,
    reason                       character varying(255) DEFAULT NULL::character varying,
    created_by                   integer,
    created_at                   timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT priest_unavailability_pkey PRIMARY KEY (unavailability_id)
);

CREATE TABLE IF NOT EXISTS public.priests (
    priest_id                    SERIAL,
    user_id                      integer,
    full_name                    character varying(150) NOT NULL,
    title                        character varying(50) DEFAULT 'Rev. Fr.'::character varying,
    contact_number               character varying(20),
    email                        character varying(150),
    status                       priest_status DEFAULT 'active'::priest_status,
    created_at                   timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT priests_pkey PRIMARY KEY (priest_id)
);

CREATE TABLE IF NOT EXISTS public.projects (
    project_id                   SERIAL,
    project_name                 character varying(150) NOT NULL,
    description                  text,
    target_amount                numeric(12,2) NOT NULL DEFAULT 0,
    is_active                    boolean DEFAULT true,
    created_by                   integer,
    created_at                   timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT projects_pkey PRIMARY KEY (project_id)
);

CREATE TABLE IF NOT EXISTS public.requirements (
    requirement_id               SERIAL,
    service_id                   integer NOT NULL,
    requirement_name             character varying(150) NOT NULL,
    is_mandatory                 boolean DEFAULT true,
    CONSTRAINT requirements_pkey PRIMARY KEY (requirement_id)
);

CREATE TABLE IF NOT EXISTS public.roles (
    role_id                      SERIAL,
    role_name                    character varying(50) NOT NULL,
    description                  character varying(255),
    created_at                   timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT roles_pkey PRIMARY KEY (role_id),
    CONSTRAINT roles_role_name_key UNIQUE (role_name)
);

CREATE TABLE IF NOT EXISTS public.service_fee_rules (
    fee_rule_id                  SERIAL,
    service_category             character varying(50) NOT NULL,
    schedule_type                character varying(10) NOT NULL,
    pss_classification           character varying(20) NOT NULL,
    base_fee                     numeric(10,2) NOT NULL DEFAULT 0,
    priest_stipend               numeric(10,2) NOT NULL DEFAULT 0,
    included_sponsors            integer NOT NULL DEFAULT 0,
    additional_sponsor_fee       numeric(10,2) NOT NULL DEFAULT 0,
    CONSTRAINT service_fee_rules_pkey PRIMARY KEY (fee_rule_id),
    CONSTRAINT service_fee_rules_service_category_schedule_type_pss_classi_key UNIQUE (service_category, schedule_type, pss_classification),
    CONSTRAINT service_fee_rules_pss_classification_check CHECK (((pss_classification)::text = ANY ((ARRAY['pss'::character varying, 'non_pss'::character varying])::text[]))),
    CONSTRAINT service_fee_rules_schedule_type_check CHECK (((schedule_type)::text = ANY ((ARRAY['Regular'::character varying, 'Special'::character varying, 'Any'::character varying])::text[])))
);

CREATE TABLE IF NOT EXISTS public.service_schedules (
    schedule_id                  SERIAL,
    service_id                   integer NOT NULL,
    weekday                      smallint NOT NULL,
    occurrence                   smallint,
    slot_time                    time without time zone NOT NULL,
    is_active                    boolean DEFAULT true,
    created_by                   integer,
    created_at                   timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT service_schedules_pkey PRIMARY KEY (schedule_id),
    CONSTRAINT service_schedules_occurrence_check CHECK (((occurrence >= 1) AND (occurrence <= 5))),
    CONSTRAINT service_schedules_weekday_check CHECK (((weekday >= 0) AND (weekday <= 6)))
);

CREATE TABLE IF NOT EXISTS public.services (
    service_id                   SERIAL,
    service_name                 character varying(150) NOT NULL,
    category                     service_category NOT NULL,
    description                  text,
    fee                          numeric(10,2) NOT NULL DEFAULT 0.00,
    requirements                 text,
    duration_minutes             integer DEFAULT 60,
    is_active                    boolean DEFAULT true,
    created_at                   timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at                   timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT services_pkey PRIMARY KEY (service_id)
);

CREATE TABLE IF NOT EXISTS public.settings (
    setting_key                  character varying(100) NOT NULL,
    setting_value                text,
    updated_at                   timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT settings_pkey PRIMARY KEY (setting_key)
);

CREATE TABLE IF NOT EXISTS public.transactions (
    transaction_id               SERIAL,
    payment_id                   integer NOT NULL,
    gateway                      character varying(50),
    gateway_transaction_id       character varying(150),
    status                       character varying(50),
    raw_response                 text,
    created_at                   timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT transactions_pkey PRIMARY KEY (transaction_id)
);

CREATE TABLE IF NOT EXISTS public.uploaded_documents (
    document_id                  SERIAL,
    appointment_id               integer,
    requirement_id               integer,
    requirement_label            character varying(255) DEFAULT NULL::character varying,
    file_name                    character varying(255) NOT NULL,
    file_path                    character varying(255) NOT NULL,
    file_type                    character varying(50),
    uploaded_at                  timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    verified                     boolean DEFAULT false,
    review_status                character varying(20),
    rejection_reason             text,
    reviewed_by                  integer,
    reviewed_at                  timestamp with time zone,
    superseded_by                integer,
    document_source              character varying(20) NOT NULL DEFAULT 'uploaded'::character varying,
    generated_form_type          character varying(50),
    draft_id                     bigint,
    CONSTRAINT uploaded_documents_pkey PRIMARY KEY (document_id),
    CONSTRAINT uploaded_documents_document_source_check CHECK (((document_source)::text = ANY ((ARRAY['uploaded'::character varying, 'generated'::character varying])::text[]))),
    CONSTRAINT uploaded_documents_generated_form_type_check_v4 CHECK (((generated_form_type IS NULL) OR ((generated_form_type)::text = ANY ((ARRAY['matrimony_application'::character varying, 'cluster_clearance'::character varying, 'wedding_sponsor_clearance'::character varying, 'katin_awan_bunyag'::character varying, 'cluster_clearance_baptism_sponsor'::character varying, 'katin_awan_paglubong'::character varying, 'official_certificate'::character varying])::text[])))),
    CONSTRAINT uploaded_documents_owner_check CHECK (((appointment_id IS NOT NULL) <> (draft_id IS NOT NULL))),
    CONSTRAINT uploaded_documents_review_status_check CHECK (((review_status)::text = ANY ((ARRAY['pending'::character varying, 'approved'::character varying, 'rejected'::character varying])::text[])))
);

CREATE TABLE IF NOT EXISTS public.users (
    user_id                      SERIAL,
    role_id                      integer NOT NULL,
    firstname                    character varying(100) NOT NULL,
    lastname                     character varying(100) NOT NULL,
    middlename                   character varying(100) DEFAULT NULL::character varying,
    email                        character varying(150) NOT NULL,
    password                     character varying(255) NOT NULL,
    phone                        character varying(20),
    address                      character varying(255),
    birthdate                    date,
    gender                       user_gender,
    profile_photo                character varying(255) DEFAULT NULL::character varying,
    status                       user_status DEFAULT 'active'::user_status,
    must_change_password         smallint DEFAULT 0,
    created_at                   timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at                   timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    email_verified_at            timestamp without time zone,
    verification_token           character varying(64) DEFAULT NULL::character varying,
    token_expires_at             timestamp without time zone,
    CONSTRAINT users_pkey PRIMARY KEY (user_id),
    CONSTRAINT users_email_key UNIQUE (email)
);

-- -----------------------------------------------------------------------------
-- 3. FOREIGN KEYS
-- -----------------------------------------------------------------------------
ALTER TABLE public.activity_logs DROP CONSTRAINT IF EXISTS activity_logs_user_id_fkey;
ALTER TABLE public.activity_logs ADD CONSTRAINT activity_logs_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(user_id) ON DELETE SET NULL;
ALTER TABLE public.announcements DROP CONSTRAINT IF EXISTS announcements_posted_by_fkey;
ALTER TABLE public.announcements ADD CONSTRAINT announcements_posted_by_fkey FOREIGN KEY (posted_by) REFERENCES public.users(user_id) ON DELETE CASCADE;
ALTER TABLE public.appointment_drafts DROP CONSTRAINT IF EXISTS appointment_drafts_finalized_appointment_id_fkey;
ALTER TABLE public.appointment_drafts ADD CONSTRAINT appointment_drafts_finalized_appointment_id_fkey FOREIGN KEY (finalized_appointment_id) REFERENCES public.appointments(appointment_id) ON DELETE RESTRICT;
ALTER TABLE public.appointment_drafts DROP CONSTRAINT IF EXISTS appointment_drafts_parishioner_id_fkey;
ALTER TABLE public.appointment_drafts ADD CONSTRAINT appointment_drafts_parishioner_id_fkey FOREIGN KEY (parishioner_id) REFERENCES public.parishioners(parishioner_id) ON DELETE CASCADE;
ALTER TABLE public.appointment_drafts DROP CONSTRAINT IF EXISTS appointment_drafts_priest_id_fkey;
ALTER TABLE public.appointment_drafts ADD CONSTRAINT appointment_drafts_priest_id_fkey FOREIGN KEY (priest_id) REFERENCES public.priests(priest_id) ON DELETE SET NULL;
ALTER TABLE public.appointment_drafts DROP CONSTRAINT IF EXISTS appointment_drafts_service_id_fkey;
ALTER TABLE public.appointment_drafts ADD CONSTRAINT appointment_drafts_service_id_fkey FOREIGN KEY (service_id) REFERENCES public.services(service_id) ON DELETE RESTRICT;
ALTER TABLE public.appointments DROP CONSTRAINT IF EXISTS appointments_approved_by_fkey;
ALTER TABLE public.appointments ADD CONSTRAINT appointments_approved_by_fkey FOREIGN KEY (approved_by) REFERENCES public.users(user_id) ON DELETE SET NULL;
ALTER TABLE public.appointments DROP CONSTRAINT IF EXISTS appointments_confirmed_by_fkey;
ALTER TABLE public.appointments ADD CONSTRAINT appointments_confirmed_by_fkey FOREIGN KEY (confirmed_by) REFERENCES public.users(user_id) ON DELETE SET NULL;
ALTER TABLE public.appointments DROP CONSTRAINT IF EXISTS appointments_parishioner_id_fkey;
ALTER TABLE public.appointments ADD CONSTRAINT appointments_parishioner_id_fkey FOREIGN KEY (parishioner_id) REFERENCES public.parishioners(parishioner_id) ON DELETE CASCADE;
ALTER TABLE public.appointments DROP CONSTRAINT IF EXISTS appointments_priest_id_fkey;
ALTER TABLE public.appointments ADD CONSTRAINT appointments_priest_id_fkey FOREIGN KEY (priest_id) REFERENCES public.priests(priest_id) ON DELETE SET NULL;
ALTER TABLE public.appointments DROP CONSTRAINT IF EXISTS appointments_pss_verified_by_fkey;
ALTER TABLE public.appointments ADD CONSTRAINT appointments_pss_verified_by_fkey FOREIGN KEY (pss_verified_by) REFERENCES public.users(user_id) ON DELETE SET NULL;
ALTER TABLE public.appointments DROP CONSTRAINT IF EXISTS appointments_service_id_fkey;
ALTER TABLE public.appointments ADD CONSTRAINT appointments_service_id_fkey FOREIGN KEY (service_id) REFERENCES public.services(service_id);
ALTER TABLE public.appointments DROP CONSTRAINT IF EXISTS appointments_status_id_fkey;
ALTER TABLE public.appointments ADD CONSTRAINT appointments_status_id_fkey FOREIGN KEY (status_id) REFERENCES public.appointment_status(status_id);
ALTER TABLE public.calendar DROP CONSTRAINT IF EXISTS calendar_created_by_fkey;
ALTER TABLE public.calendar ADD CONSTRAINT calendar_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(user_id) ON DELETE SET NULL;
ALTER TABLE public.chat_messages DROP CONSTRAINT IF EXISTS chat_messages_user_id_fkey;
ALTER TABLE public.chat_messages ADD CONSTRAINT chat_messages_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(user_id) ON DELETE SET NULL;
ALTER TABLE public.donations DROP CONSTRAINT IF EXISTS donations_appointment_id_fkey;
ALTER TABLE public.donations ADD CONSTRAINT donations_appointment_id_fkey FOREIGN KEY (appointment_id) REFERENCES public.appointments(appointment_id) ON DELETE CASCADE;
ALTER TABLE public.donations DROP CONSTRAINT IF EXISTS donations_project_id_fkey;
ALTER TABLE public.donations ADD CONSTRAINT donations_project_id_fkey FOREIGN KEY (project_id) REFERENCES public.projects(project_id) ON DELETE SET NULL;
ALTER TABLE public.email_logs DROP CONSTRAINT IF EXISTS email_logs_appointment_id_fkey;
ALTER TABLE public.email_logs ADD CONSTRAINT email_logs_appointment_id_fkey FOREIGN KEY (appointment_id) REFERENCES public.appointments(appointment_id) ON DELETE SET NULL;
ALTER TABLE public.events DROP CONSTRAINT IF EXISTS events_created_by_fkey;
ALTER TABLE public.events ADD CONSTRAINT events_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(user_id) ON DELETE SET NULL;
ALTER TABLE public.events DROP CONSTRAINT IF EXISTS events_location_id_fkey;
ALTER TABLE public.events ADD CONSTRAINT events_location_id_fkey FOREIGN KEY (location_id) REFERENCES public.locations(location_id) ON DELETE SET NULL;
ALTER TABLE public.events DROP CONSTRAINT IF EXISTS events_priest_id_fkey;
ALTER TABLE public.events ADD CONSTRAINT events_priest_id_fkey FOREIGN KEY (priest_id) REFERENCES public.priests(priest_id) ON DELETE SET NULL;
ALTER TABLE public.generated_forms DROP CONSTRAINT IF EXISTS generated_forms_appointment_id_fkey;
ALTER TABLE public.generated_forms ADD CONSTRAINT generated_forms_appointment_id_fkey FOREIGN KEY (appointment_id) REFERENCES public.appointments(appointment_id) ON DELETE CASCADE;
ALTER TABLE public.generated_forms DROP CONSTRAINT IF EXISTS generated_forms_document_id_fkey;
ALTER TABLE public.generated_forms ADD CONSTRAINT generated_forms_document_id_fkey FOREIGN KEY (document_id) REFERENCES public.uploaded_documents(document_id) ON DELETE SET NULL;
ALTER TABLE public.generated_forms DROP CONSTRAINT IF EXISTS generated_forms_draft_fk;
ALTER TABLE public.generated_forms ADD CONSTRAINT generated_forms_draft_fk FOREIGN KEY (draft_id) REFERENCES public.appointment_drafts(draft_id) ON DELETE CASCADE;
ALTER TABLE public.issued_certificates DROP CONSTRAINT IF EXISTS issued_certificates_appointment_id_fkey;
ALTER TABLE public.issued_certificates ADD CONSTRAINT issued_certificates_appointment_id_fkey FOREIGN KEY (appointment_id) REFERENCES public.appointments(appointment_id) ON DELETE CASCADE;
ALTER TABLE public.issued_certificates DROP CONSTRAINT IF EXISTS issued_certificates_claimed_by_user_id_fkey;
ALTER TABLE public.issued_certificates ADD CONSTRAINT issued_certificates_claimed_by_user_id_fkey FOREIGN KEY (claimed_by_user_id) REFERENCES public.users(user_id) ON DELETE SET NULL;
ALTER TABLE public.issued_certificates DROP CONSTRAINT IF EXISTS issued_certificates_document_id_fkey;
ALTER TABLE public.issued_certificates ADD CONSTRAINT issued_certificates_document_id_fkey FOREIGN KEY (document_id) REFERENCES public.uploaded_documents(document_id) ON DELETE SET NULL;
ALTER TABLE public.issued_certificates DROP CONSTRAINT IF EXISTS issued_certificates_issued_by_fkey;
ALTER TABLE public.issued_certificates ADD CONSTRAINT issued_certificates_issued_by_fkey FOREIGN KEY (issued_by) REFERENCES public.users(user_id) ON DELETE SET NULL;
ALTER TABLE public.issued_certificates DROP CONSTRAINT IF EXISTS issued_certificates_source_appointment_id_fkey;
ALTER TABLE public.issued_certificates ADD CONSTRAINT issued_certificates_source_appointment_id_fkey FOREIGN KEY (source_appointment_id) REFERENCES public.appointments(appointment_id) ON DELETE SET NULL;
ALTER TABLE public.mass_intentions DROP CONSTRAINT IF EXISTS mass_intentions_appointment_id_fkey;
ALTER TABLE public.mass_intentions ADD CONSTRAINT mass_intentions_appointment_id_fkey FOREIGN KEY (appointment_id) REFERENCES public.appointments(appointment_id) ON DELETE CASCADE;
ALTER TABLE public.notifications DROP CONSTRAINT IF EXISTS notifications_appointment_id_fkey;
ALTER TABLE public.notifications ADD CONSTRAINT notifications_appointment_id_fkey FOREIGN KEY (appointment_id) REFERENCES public.appointments(appointment_id) ON DELETE CASCADE;
ALTER TABLE public.notifications DROP CONSTRAINT IF EXISTS notifications_user_id_fkey;
ALTER TABLE public.notifications ADD CONSTRAINT notifications_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(user_id) ON DELETE CASCADE;
ALTER TABLE public.official_receipts DROP CONSTRAINT IF EXISTS official_receipts_issued_by_fkey;
ALTER TABLE public.official_receipts ADD CONSTRAINT official_receipts_issued_by_fkey FOREIGN KEY (issued_by) REFERENCES public.users(user_id);
ALTER TABLE public.official_receipts DROP CONSTRAINT IF EXISTS official_receipts_payment_id_fkey;
ALTER TABLE public.official_receipts ADD CONSTRAINT official_receipts_payment_id_fkey FOREIGN KEY (payment_id) REFERENCES public.payments(payment_id) ON DELETE CASCADE;
ALTER TABLE public.parishioners DROP CONSTRAINT IF EXISTS parishioners_user_id_fkey;
ALTER TABLE public.parishioners ADD CONSTRAINT parishioners_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(user_id) ON DELETE CASCADE;
ALTER TABLE public.payments DROP CONSTRAINT IF EXISTS payments_appointment_id_fkey;
ALTER TABLE public.payments ADD CONSTRAINT payments_appointment_id_fkey FOREIGN KEY (appointment_id) REFERENCES public.appointments(appointment_id) ON DELETE CASCADE;
ALTER TABLE public.payments DROP CONSTRAINT IF EXISTS payments_method_id_fkey;
ALTER TABLE public.payments ADD CONSTRAINT payments_method_id_fkey FOREIGN KEY (method_id) REFERENCES public.payment_methods(method_id);
ALTER TABLE public.payments DROP CONSTRAINT IF EXISTS payments_verified_by_fkey;
ALTER TABLE public.payments ADD CONSTRAINT payments_verified_by_fkey FOREIGN KEY (verified_by) REFERENCES public.users(user_id) ON DELETE SET NULL;
ALTER TABLE public.priest_unavailability DROP CONSTRAINT IF EXISTS priest_unavailability_created_by_fkey;
ALTER TABLE public.priest_unavailability ADD CONSTRAINT priest_unavailability_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(user_id) ON DELETE SET NULL;
ALTER TABLE public.priest_unavailability DROP CONSTRAINT IF EXISTS priest_unavailability_priest_id_fkey;
ALTER TABLE public.priest_unavailability ADD CONSTRAINT priest_unavailability_priest_id_fkey FOREIGN KEY (priest_id) REFERENCES public.priests(priest_id) ON DELETE CASCADE;
ALTER TABLE public.priests DROP CONSTRAINT IF EXISTS priests_user_id_fkey;
ALTER TABLE public.priests ADD CONSTRAINT priests_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(user_id) ON DELETE SET NULL;
ALTER TABLE public.projects DROP CONSTRAINT IF EXISTS projects_created_by_fkey;
ALTER TABLE public.projects ADD CONSTRAINT projects_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(user_id) ON DELETE SET NULL;
ALTER TABLE public.requirements DROP CONSTRAINT IF EXISTS requirements_service_id_fkey;
ALTER TABLE public.requirements ADD CONSTRAINT requirements_service_id_fkey FOREIGN KEY (service_id) REFERENCES public.services(service_id) ON DELETE CASCADE;
ALTER TABLE public.service_schedules DROP CONSTRAINT IF EXISTS service_schedules_created_by_fkey;
ALTER TABLE public.service_schedules ADD CONSTRAINT service_schedules_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(user_id) ON DELETE SET NULL;
ALTER TABLE public.service_schedules DROP CONSTRAINT IF EXISTS service_schedules_service_id_fkey;
ALTER TABLE public.service_schedules ADD CONSTRAINT service_schedules_service_id_fkey FOREIGN KEY (service_id) REFERENCES public.services(service_id) ON DELETE CASCADE;
ALTER TABLE public.transactions DROP CONSTRAINT IF EXISTS transactions_payment_id_fkey;
ALTER TABLE public.transactions ADD CONSTRAINT transactions_payment_id_fkey FOREIGN KEY (payment_id) REFERENCES public.payments(payment_id) ON DELETE CASCADE;
ALTER TABLE public.uploaded_documents DROP CONSTRAINT IF EXISTS uploaded_documents_appointment_id_fkey;
ALTER TABLE public.uploaded_documents ADD CONSTRAINT uploaded_documents_appointment_id_fkey FOREIGN KEY (appointment_id) REFERENCES public.appointments(appointment_id) ON DELETE CASCADE;
ALTER TABLE public.uploaded_documents DROP CONSTRAINT IF EXISTS uploaded_documents_draft_fk;
ALTER TABLE public.uploaded_documents ADD CONSTRAINT uploaded_documents_draft_fk FOREIGN KEY (draft_id) REFERENCES public.appointment_drafts(draft_id) ON DELETE CASCADE;
ALTER TABLE public.uploaded_documents DROP CONSTRAINT IF EXISTS uploaded_documents_requirement_id_fkey;
ALTER TABLE public.uploaded_documents ADD CONSTRAINT uploaded_documents_requirement_id_fkey FOREIGN KEY (requirement_id) REFERENCES public.requirements(requirement_id) ON DELETE SET NULL;
ALTER TABLE public.uploaded_documents DROP CONSTRAINT IF EXISTS uploaded_documents_reviewed_by_fkey;
ALTER TABLE public.uploaded_documents ADD CONSTRAINT uploaded_documents_reviewed_by_fkey FOREIGN KEY (reviewed_by) REFERENCES public.users(user_id) ON DELETE SET NULL;
ALTER TABLE public.uploaded_documents DROP CONSTRAINT IF EXISTS uploaded_documents_superseded_by_fkey;
ALTER TABLE public.uploaded_documents ADD CONSTRAINT uploaded_documents_superseded_by_fkey FOREIGN KEY (superseded_by) REFERENCES public.uploaded_documents(document_id) ON DELETE SET NULL;
ALTER TABLE public.users DROP CONSTRAINT IF EXISTS users_role_id_fkey;
ALTER TABLE public.users ADD CONSTRAINT users_role_id_fkey FOREIGN KEY (role_id) REFERENCES public.roles(role_id);

-- -----------------------------------------------------------------------------
-- 4. INDEXES (indexes backing PK/UNIQUE constraints are created by §2)
-- -----------------------------------------------------------------------------
CREATE INDEX IF NOT EXISTS idx_cleanup_activity_logs_user_created ON public.activity_logs USING btree (user_id, created_at);
CREATE INDEX IF NOT EXISTS idx_app_sessions_expires_at ON public.app_sessions USING btree (expires_at);
CREATE INDEX IF NOT EXISTS idx_cleanup_drafts_parishioner ON public.appointment_drafts USING btree (parishioner_id);
CREATE INDEX IF NOT EXISTS idx_appointments_payment_deadline ON public.appointments USING btree (payment_deadline);
CREATE INDEX IF NOT EXISTS idx_appointments_requirements_review_status ON public.appointments USING btree (requirements_review_status) WHERE (requirements_review_status IS NOT NULL);
CREATE INDEX IF NOT EXISTS idx_appt_date ON public.appointments USING btree (appointment_date);
CREATE INDEX IF NOT EXISTS idx_appt_status ON public.appointments USING btree (status_id);
CREATE INDEX IF NOT EXISTS idx_cleanup_appointments_parishioner ON public.appointments USING btree (parishioner_id);
CREATE INDEX IF NOT EXISTS idx_cleanup_appointments_priest_date ON public.appointments USING btree (priest_id, appointment_date);
CREATE INDEX IF NOT EXISTS idx_cleanup_appointments_service ON public.appointments USING btree (service_id);
CREATE INDEX IF NOT EXISTS idx_auth_throttle_locked_until ON public.auth_throttle USING btree (locked_until);
CREATE INDEX IF NOT EXISTS idx_cleanup_donations_appointment ON public.donations USING btree (appointment_id);
CREATE INDEX IF NOT EXISTS idx_cleanup_donations_project ON public.donations USING btree (project_id);
CREATE INDEX IF NOT EXISTS idx_email_logs_appointment ON public.email_logs USING btree (appointment_id);
CREATE UNIQUE INDEX IF NOT EXISTS idx_generated_forms_appt_type ON public.generated_forms USING btree (appointment_id, form_type) WHERE (appointment_id IS NOT NULL);
CREATE INDEX IF NOT EXISTS idx_generated_forms_category ON public.generated_forms USING btree (service_category);
CREATE INDEX IF NOT EXISTS idx_generated_forms_document ON public.generated_forms USING btree (document_id);
CREATE UNIQUE INDEX IF NOT EXISTS idx_generated_forms_draft_type ON public.generated_forms USING btree (draft_id, form_type) WHERE (draft_id IS NOT NULL);
CREATE UNIQUE INDEX IF NOT EXISTS idx_issued_certificates_appointment ON public.issued_certificates USING btree (appointment_id);
CREATE INDEX IF NOT EXISTS idx_issued_certificates_document ON public.issued_certificates USING btree (document_id);
CREATE INDEX IF NOT EXISTS idx_issued_certificates_source ON public.issued_certificates USING btree (source_appointment_id);
CREATE INDEX IF NOT EXISTS idx_cleanup_mass_intentions_appointment ON public.mass_intentions USING btree (appointment_id);
CREATE INDEX IF NOT EXISTS idx_cleanup_notifications_appointment ON public.notifications USING btree (appointment_id);
CREATE INDEX IF NOT EXISTS idx_notifications_user_unread ON public.notifications USING btree (user_id, is_read);
CREATE INDEX IF NOT EXISTS idx_cleanup_payments_appointment ON public.payments USING btree (appointment_id);
CREATE INDEX IF NOT EXISTS idx_priest_unavail_priest_date ON public.priest_unavailability USING btree (priest_id, unavailable_date);
CREATE INDEX IF NOT EXISTS idx_service_schedules_service ON public.service_schedules USING btree (service_id);
CREATE INDEX IF NOT EXISTS idx_cleanup_transactions_payment ON public.transactions USING btree (payment_id);
CREATE INDEX IF NOT EXISTS idx_cleanup_uploaded_docs_appointment ON public.uploaded_documents USING btree (appointment_id);
CREATE INDEX IF NOT EXISTS idx_uploaded_documents_draft ON public.uploaded_documents USING btree (draft_id);
CREATE INDEX IF NOT EXISTS idx_uploaded_documents_generated_type ON public.uploaded_documents USING btree (generated_form_type);
CREATE INDEX IF NOT EXISTS idx_uploaded_documents_review_status ON public.uploaded_documents USING btree (review_status);
CREATE INDEX IF NOT EXISTS idx_uploaded_documents_superseded_by ON public.uploaded_documents USING btree (superseded_by);

-- -----------------------------------------------------------------------------
-- 5. FUNCTIONS (business-logic trigger functions)
-- -----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION public.fn_appointment_status_name(p_status_id integer)
 RETURNS text
 LANGUAGE sql
 STABLE
AS $function$
    SELECT status_name FROM appointment_status WHERE status_id = p_status_id;
$function$;

CREATE OR REPLACE FUNCTION public.fn_log_appointment_status_change()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
DECLARE
    v_old_name TEXT;
    v_new_name TEXT;
BEGIN
    -- Only fire on actual status changes
    IF OLD.status_id IS NOT DISTINCT FROM NEW.status_id THEN
        RETURN NEW;
    END IF;

    v_old_name := fn_appointment_status_name(OLD.status_id);
    v_new_name := fn_appointment_status_name(NEW.status_id);

    INSERT INTO activity_logs (user_id, action, module)
    VALUES (
        NEW.approved_by,
        'Appointment #' || NEW.appointment_id
            || ' status changed: ' || COALESCE(v_old_name, OLD.status_id::text)
            || ' → ' || COALESCE(v_new_name, NEW.status_id::text),
        'Appointments'
    );

    RETURN NEW;
END;
$function$;

CREATE OR REPLACE FUNCTION public.fn_notify_appointment_status()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
DECLARE
    v_user_id INT;
    v_title   TEXT;
    v_message TEXT;
BEGIN
    -- Only fire on actual status changes
    IF OLD.status_id IS NOT DISTINCT FROM NEW.status_id THEN
        RETURN NEW;
    END IF;

    -- Skip statuses handled by PHP or by another notification path:
    --   3 = Rejected        (PHP sends personalized message with rejection reason)
    --   4 = Payment Verified (functions.php:verifyPaymentAndIssueReceipt already notifies)
    IF NEW.status_id IN (3, 4) THEN
        RETURN NEW;
    END IF;

    -- Resolve the parishioner's website user_id
    SELECT u.user_id INTO v_user_id
    FROM parishioners p
    JOIN users u ON u.user_id = p.user_id
    WHERE p.parishioner_id = NEW.parishioner_id;

    IF v_user_id IS NULL THEN
        RETURN NEW;
    END IF;

    -- Map status to notification title and message
    v_title := CASE NEW.status_id
        WHEN 2 THEN 'Appointment Approved'
        WHEN 5 THEN 'Appointment Confirmed'
        WHEN 6 THEN 'Appointment Completed'
        WHEN 7 THEN 'Appointment Cancelled'
        ELSE NULL
    END;

    v_message := CASE NEW.status_id
        WHEN 2 THEN 'Your appointment #' || NEW.appointment_id
                    || ' has been approved. Please proceed with payment.'
        WHEN 5 THEN 'Your appointment #' || NEW.appointment_id
                    || ' is confirmed. We look forward to seeing you.'
        WHEN 6 THEN 'Your appointment #' || NEW.appointment_id
                    || ' has been completed. Thank you.'
        WHEN 7 THEN 'Your appointment #' || NEW.appointment_id
                    || ' has been cancelled.'
        ELSE NULL
    END;

    IF v_title IS NOT NULL THEN
        INSERT INTO notifications (user_id, type, category, title, message)
        VALUES (v_user_id, 'website', 'appointment', v_title, v_message);
    END IF;

    RETURN NEW;
END;
$function$;

CREATE OR REPLACE FUNCTION public.fn_protect_guest_placeholder()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
BEGIN
    IF OLD.email LIKE '%@parishhub.internal'
       AND (TG_OP = 'DELETE' OR NEW.email IS DISTINCT FROM OLD.email) THEN
        RAISE EXCEPTION 'System placeholder account % cannot be deleted or renamed: guest and walk-in bookings, payments and certificates depend on it.', OLD.email
            USING ERRCODE = 'restrict_violation';
    END IF;
    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END;
$function$;

CREATE OR REPLACE FUNCTION public.fn_set_receipt_number()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
BEGIN
    IF NEW.receipt_number IS NULL OR NEW.receipt_number = '' THEN
        NEW.receipt_number :=
            'OR-' || to_char(CURRENT_DATE, 'YYYY') || '-'
            || LPAD(NEW.payment_id::text, 6, '0');
    END IF;
    RETURN NEW;
END;
$function$;

CREATE OR REPLACE FUNCTION public.trigger_set_timestamp()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
BEGIN
  NEW.updated_at = CURRENT_TIMESTAMP;
  RETURN NEW;
END;
$function$;

-- -----------------------------------------------------------------------------
-- 6. TRIGGERS
-- -----------------------------------------------------------------------------
DROP TRIGGER IF EXISTS set_timestamp_announcements ON public.announcements;
CREATE TRIGGER set_timestamp_announcements BEFORE UPDATE ON public.announcements FOR EACH ROW EXECUTE FUNCTION trigger_set_timestamp();
DROP TRIGGER IF EXISTS set_timestamp_appointments ON public.appointments;
CREATE TRIGGER set_timestamp_appointments BEFORE UPDATE ON public.appointments FOR EACH ROW EXECUTE FUNCTION trigger_set_timestamp();
DROP TRIGGER IF EXISTS trg_appointment_notify ON public.appointments;
CREATE TRIGGER trg_appointment_notify AFTER UPDATE OF status_id ON public.appointments FOR EACH ROW EXECUTE FUNCTION fn_notify_appointment_status();
DROP TRIGGER IF EXISTS trg_appointment_status_log ON public.appointments;
CREATE TRIGGER trg_appointment_status_log AFTER UPDATE OF status_id ON public.appointments FOR EACH ROW EXECUTE FUNCTION fn_log_appointment_status_change();
DROP TRIGGER IF EXISTS set_timestamp_issued_certificates ON public.issued_certificates;
CREATE TRIGGER set_timestamp_issued_certificates BEFORE UPDATE ON public.issued_certificates FOR EACH ROW EXECUTE FUNCTION trigger_set_timestamp();
DROP TRIGGER IF EXISTS trg_set_receipt_number ON public.official_receipts;
CREATE TRIGGER trg_set_receipt_number BEFORE INSERT ON public.official_receipts FOR EACH ROW EXECUTE FUNCTION fn_set_receipt_number();
DROP TRIGGER IF EXISTS set_timestamp_services ON public.services;
CREATE TRIGGER set_timestamp_services BEFORE UPDATE ON public.services FOR EACH ROW EXECUTE FUNCTION trigger_set_timestamp();
DROP TRIGGER IF EXISTS set_timestamp_settings ON public.settings;
CREATE TRIGGER set_timestamp_settings BEFORE UPDATE ON public.settings FOR EACH ROW EXECUTE FUNCTION trigger_set_timestamp();
DROP TRIGGER IF EXISTS set_timestamp_users ON public.users;
CREATE TRIGGER set_timestamp_users BEFORE UPDATE ON public.users FOR EACH ROW EXECUTE FUNCTION trigger_set_timestamp();
DROP TRIGGER IF EXISTS trg_protect_guest_placeholder ON public.users;
CREATE TRIGGER trg_protect_guest_placeholder BEFORE DELETE OR UPDATE OF email ON public.users FOR EACH ROW EXECUTE FUNCTION fn_protect_guest_placeholder();

-- -----------------------------------------------------------------------------
-- 7. PRIVILEGES (from migration_service_fee_rules.sql — fee rules are server-side only)
-- -----------------------------------------------------------------------------
DO $$ BEGIN
    REVOKE ALL PRIVILEGES ON TABLE public.service_fee_rules FROM PUBLIC;
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'anon') THEN REVOKE ALL PRIVILEGES ON TABLE public.service_fee_rules FROM anon; END IF;
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'authenticated') THEN REVOKE ALL PRIVILEGES ON TABLE public.service_fee_rules FROM authenticated; END IF;
END $$;

-- -----------------------------------------------------------------------------
-- 8. REFERENCE DATA the PHP code depends on (inserted only if missing)
--    roles / appointment_status / payment_methods ids are hard-coded in PHP
--    (e.g. status_id 1..7, method_id 7 = PayMongo) — do not renumber.
-- -----------------------------------------------------------------------------
INSERT INTO public.roles (role_id, role_name, description)
SELECT 1, 'Parishioner', 'Regular parishioner user account'
WHERE NOT EXISTS (SELECT 1 FROM public.roles WHERE role_id = 1);
INSERT INTO public.roles (role_id, role_name, description)
SELECT 2, 'Secretary', 'Parish office secretary - reviews appointments'
WHERE NOT EXISTS (SELECT 1 FROM public.roles WHERE role_id = 2);
INSERT INTO public.roles (role_id, role_name, description)
SELECT 3, 'Treasurer', 'Parish office treasurer - verifies payments and issues receipts'
WHERE NOT EXISTS (SELECT 1 FROM public.roles WHERE role_id = 3);
INSERT INTO public.roles (role_id, role_name, description)
SELECT 4, 'Admin', 'System administrator'
WHERE NOT EXISTS (SELECT 1 FROM public.roles WHERE role_id = 4);
INSERT INTO public.roles (role_id, role_name, description)
SELECT 5, 'Priest', 'Parish priest - view-only schedule and Mass Intention access'
WHERE NOT EXISTS (SELECT 1 FROM public.roles WHERE role_id = 5);
INSERT INTO public.appointment_status (status_id, status_name)
SELECT 1, 'Pending'
WHERE NOT EXISTS (SELECT 1 FROM public.appointment_status WHERE status_id = 1);
INSERT INTO public.appointment_status (status_id, status_name)
SELECT 2, 'Approved'
WHERE NOT EXISTS (SELECT 1 FROM public.appointment_status WHERE status_id = 2);
INSERT INTO public.appointment_status (status_id, status_name)
SELECT 3, 'Rejected'
WHERE NOT EXISTS (SELECT 1 FROM public.appointment_status WHERE status_id = 3);
INSERT INTO public.appointment_status (status_id, status_name)
SELECT 4, 'Payment Verified'
WHERE NOT EXISTS (SELECT 1 FROM public.appointment_status WHERE status_id = 4);
INSERT INTO public.appointment_status (status_id, status_name)
SELECT 5, 'Confirmed'
WHERE NOT EXISTS (SELECT 1 FROM public.appointment_status WHERE status_id = 5);
INSERT INTO public.appointment_status (status_id, status_name)
SELECT 6, 'Completed'
WHERE NOT EXISTS (SELECT 1 FROM public.appointment_status WHERE status_id = 6);
INSERT INTO public.appointment_status (status_id, status_name)
SELECT 7, 'Cancelled'
WHERE NOT EXISTS (SELECT 1 FROM public.appointment_status WHERE status_id = 7);
INSERT INTO public.payment_methods (method_id, method_name)
SELECT 1, 'Cash'
WHERE NOT EXISTS (SELECT 1 FROM public.payment_methods WHERE method_id = 1);
INSERT INTO public.payment_methods (method_id, method_name)
SELECT 2, 'GCash'
WHERE NOT EXISTS (SELECT 1 FROM public.payment_methods WHERE method_id = 2);
INSERT INTO public.payment_methods (method_id, method_name)
SELECT 3, 'PayMaya'
WHERE NOT EXISTS (SELECT 1 FROM public.payment_methods WHERE method_id = 3);
INSERT INTO public.payment_methods (method_id, method_name)
SELECT 4, 'Bank Transfer'
WHERE NOT EXISTS (SELECT 1 FROM public.payment_methods WHERE method_id = 4);
INSERT INTO public.payment_methods (method_id, method_name)
SELECT 5, 'Credit/Debit Card'
WHERE NOT EXISTS (SELECT 1 FROM public.payment_methods WHERE method_id = 5);
INSERT INTO public.payment_methods (method_id, method_name)
SELECT 6, 'PayPal'
WHERE NOT EXISTS (SELECT 1 FROM public.payment_methods WHERE method_id = 6);
INSERT INTO public.payment_methods (method_id, method_name)
SELECT 7, 'PayMongo (Online)'
WHERE NOT EXISTS (SELECT 1 FROM public.payment_methods WHERE method_id = 7);
INSERT INTO public.settings (setting_key, setting_value)
SELECT 'donation_enabled', '1'
WHERE NOT EXISTS (SELECT 1 FROM public.settings WHERE setting_key = 'donation_enabled');
INSERT INTO public.service_fee_rules (service_category, schedule_type, pss_classification, base_fee, priest_stipend, included_sponsors, additional_sponsor_fee)
SELECT 'Baptism', 'Regular', 'non_pss', '1000.00', '0.00', 2, '100.00'
WHERE NOT EXISTS (SELECT 1 FROM public.service_fee_rules WHERE service_category = 'Baptism' AND schedule_type = 'Regular' AND pss_classification = 'non_pss');
INSERT INTO public.service_fee_rules (service_category, schedule_type, pss_classification, base_fee, priest_stipend, included_sponsors, additional_sponsor_fee)
SELECT 'Baptism', 'Regular', 'pss', '0.00', '0.00', 2, '100.00'
WHERE NOT EXISTS (SELECT 1 FROM public.service_fee_rules WHERE service_category = 'Baptism' AND schedule_type = 'Regular' AND pss_classification = 'pss');
INSERT INTO public.service_fee_rules (service_category, schedule_type, pss_classification, base_fee, priest_stipend, included_sponsors, additional_sponsor_fee)
SELECT 'Baptism', 'Special', 'non_pss', '1500.00', '1000.00', 2, '100.00'
WHERE NOT EXISTS (SELECT 1 FROM public.service_fee_rules WHERE service_category = 'Baptism' AND schedule_type = 'Special' AND pss_classification = 'non_pss');
INSERT INTO public.service_fee_rules (service_category, schedule_type, pss_classification, base_fee, priest_stipend, included_sponsors, additional_sponsor_fee)
SELECT 'Baptism', 'Special', 'pss', '1000.00', '1000.00', 2, '100.00'
WHERE NOT EXISTS (SELECT 1 FROM public.service_fee_rules WHERE service_category = 'Baptism' AND schedule_type = 'Special' AND pss_classification = 'pss');
INSERT INTO public.service_fee_rules (service_category, schedule_type, pss_classification, base_fee, priest_stipend, included_sponsors, additional_sponsor_fee)
SELECT 'Funeral', 'Any', 'non_pss', '2000.00', '1500.00', 0, '0.00'
WHERE NOT EXISTS (SELECT 1 FROM public.service_fee_rules WHERE service_category = 'Funeral' AND schedule_type = 'Any' AND pss_classification = 'non_pss');
INSERT INTO public.service_fee_rules (service_category, schedule_type, pss_classification, base_fee, priest_stipend, included_sponsors, additional_sponsor_fee)
SELECT 'Funeral', 'Any', 'pss', '0.00', '0.00', 0, '0.00'
WHERE NOT EXISTS (SELECT 1 FROM public.service_fee_rules WHERE service_category = 'Funeral' AND schedule_type = 'Any' AND pss_classification = 'pss');
INSERT INTO public.service_fee_rules (service_category, schedule_type, pss_classification, base_fee, priest_stipend, included_sponsors, additional_sponsor_fee)
SELECT 'Wake', 'Any', 'non_pss', '0.00', '1500.00', 0, '0.00'
WHERE NOT EXISTS (SELECT 1 FROM public.service_fee_rules WHERE service_category = 'Wake' AND schedule_type = 'Any' AND pss_classification = 'non_pss');
INSERT INTO public.service_fee_rules (service_category, schedule_type, pss_classification, base_fee, priest_stipend, included_sponsors, additional_sponsor_fee)
SELECT 'Wake', 'Any', 'pss', '0.00', '1500.00', 0, '0.00'
WHERE NOT EXISTS (SELECT 1 FROM public.service_fee_rules WHERE service_category = 'Wake' AND schedule_type = 'Any' AND pss_classification = 'pss');
INSERT INTO public.service_fee_rules (service_category, schedule_type, pss_classification, base_fee, priest_stipend, included_sponsors, additional_sponsor_fee)
SELECT 'Wedding', 'Regular', 'non_pss', '3000.00', '0.00', 4, '100.00'
WHERE NOT EXISTS (SELECT 1 FROM public.service_fee_rules WHERE service_category = 'Wedding' AND schedule_type = 'Regular' AND pss_classification = 'non_pss');
INSERT INTO public.service_fee_rules (service_category, schedule_type, pss_classification, base_fee, priest_stipend, included_sponsors, additional_sponsor_fee)
SELECT 'Wedding', 'Regular', 'pss', '0.00', '0.00', 4, '100.00'
WHERE NOT EXISTS (SELECT 1 FROM public.service_fee_rules WHERE service_category = 'Wedding' AND schedule_type = 'Regular' AND pss_classification = 'pss');
INSERT INTO public.service_fee_rules (service_category, schedule_type, pss_classification, base_fee, priest_stipend, included_sponsors, additional_sponsor_fee)
SELECT 'Wedding', 'Special', 'non_pss', '8000.00', '2000.00', 4, '100.00'
WHERE NOT EXISTS (SELECT 1 FROM public.service_fee_rules WHERE service_category = 'Wedding' AND schedule_type = 'Special' AND pss_classification = 'non_pss');
INSERT INTO public.service_fee_rules (service_category, schedule_type, pss_classification, base_fee, priest_stipend, included_sponsors, additional_sponsor_fee)
SELECT 'Wedding', 'Special', 'pss', '4000.00', '2000.00', 4, '100.00'
WHERE NOT EXISTS (SELECT 1 FROM public.service_fee_rules WHERE service_category = 'Wedding' AND schedule_type = 'Special' AND pss_classification = 'pss');
-- Services, the guest placeholder account, and priests are operational data
-- managed in the app (see migration_guest_access.sql for the guest placeholder).
-- Re-sync identity sequences after explicit-id inserts above:
SELECT setval(pg_get_serial_sequence('public.roles', 'role_id'), (SELECT COALESCE(MAX(role_id), 1) FROM public.roles));
SELECT setval(pg_get_serial_sequence('public.appointment_status', 'status_id'), (SELECT COALESCE(MAX(status_id), 1) FROM public.appointment_status));
SELECT setval(pg_get_serial_sequence('public.payment_methods', 'method_id'), (SELECT COALESCE(MAX(method_id), 1) FROM public.payment_methods));

-- -----------------------------------------------------------------------------
-- 9. LEGACY MYSQL-COMPATIBILITY FUNCTIONS (present in supabase_schema.sql; NOT
--    called by any current PHP query). Kept here, commented, for completeness.
--    Recommended action: Review / remove in a later cleanup.
-- -----------------------------------------------------------------------------
-- CREATE OR REPLACE FUNCTION public.curdate()
--  RETURNS date
--  LANGUAGE plpgsql
--  STABLE
-- AS $function$
-- BEGIN RETURN CURRENT_DATE; END; $function$;

-- CREATE OR REPLACE FUNCTION public.date_format(d timestamp with time zone, fmt text)
--  RETURNS text
--  LANGUAGE plpgsql
--  IMMUTABLE
-- AS $function$
-- BEGIN RETURN to_char(d, 'YYYY-MM'); END; $function$;

-- CREATE OR REPLACE FUNCTION public.date_format(d date, fmt text)
--  RETURNS text
--  LANGUAGE plpgsql
--  IMMUTABLE
-- AS $function$
-- BEGIN RETURN to_char(d, 'YYYY-MM'); END; $function$;

-- CREATE OR REPLACE FUNCTION public.month(d timestamp with time zone)
--  RETURNS integer
--  LANGUAGE plpgsql
--  IMMUTABLE
-- AS $function$
-- BEGIN RETURN EXTRACT(MONTH FROM d); END; $function$;

-- CREATE OR REPLACE FUNCTION public.month(d date)
--  RETURNS integer
--  LANGUAGE plpgsql
--  IMMUTABLE
-- AS $function$
-- BEGIN RETURN EXTRACT(MONTH FROM d); END; $function$;

-- CREATE OR REPLACE FUNCTION public.now()
--  RETURNS timestamp with time zone
--  LANGUAGE plpgsql
--  STABLE
-- AS $function$
-- BEGIN RETURN CURRENT_TIMESTAMP; END; $function$;

-- CREATE OR REPLACE FUNCTION public.year(d timestamp with time zone)
--  RETURNS integer
--  LANGUAGE plpgsql
--  IMMUTABLE
-- AS $function$
-- BEGIN RETURN EXTRACT(YEAR FROM d); END; $function$;

-- CREATE OR REPLACE FUNCTION public.year(d date)
--  RETURNS integer
--  LANGUAGE plpgsql
--  IMMUTABLE
-- AS $function$
-- BEGIN RETURN EXTRACT(YEAR FROM d); END; $function$;

-- CREATE OR REPLACE FUNCTION public.yearweek(d date, mode integer DEFAULT 0)
--  RETURNS integer
--  LANGUAGE plpgsql
--  IMMUTABLE
-- AS $function$
-- BEGIN RETURN (EXTRACT(YEAR FROM d) * 100 + EXTRACT(WEEK FROM d)); END; $function$;

-- CREATE OR REPLACE FUNCTION public.yearweek(d timestamp with time zone, mode integer DEFAULT 0)
--  RETURNS integer
--  LANGUAGE plpgsql
--  IMMUTABLE
-- AS $function$
-- BEGIN RETURN (EXTRACT(YEAR FROM d) * 100 + EXTRACT(WEEK FROM d)); END; $function$;

