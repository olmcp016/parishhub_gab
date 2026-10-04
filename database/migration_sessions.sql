-- Durable PHP sessions for Render/Supabase deployments.
-- Apply once before setting SESSION_DRIVER=database.

CREATE TABLE IF NOT EXISTS app_sessions (
    session_id VARCHAR(128) PRIMARY KEY,
    session_data TEXT NOT NULL,
    last_activity TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP WITH TIME ZONE NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_app_sessions_expires_at
    ON app_sessions(expires_at);
