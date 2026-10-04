<?php
/**
 * Copy this file to config/config.local.php (already gitignored) and fill
 * in real values. config.php will pick it up automatically if it exists.
 */

putenv('DB_HOST=aws-0-ap-southeast-1.pooler.supabase.com');
putenv('DB_PORT=6543');
putenv('DB_NAME=postgres');
putenv('DB_USER=postgres.gzyupwzalamtnehaywwh');
putenv('DB_PASS=ask-your-collaborator-for-this');

// Private appointment-document storage. Use local only for development;
// production should use a private Supabase Storage bucket.
putenv('DOCUMENT_STORAGE_DRIVER=local');
putenv('SUPABASE_URL=https://your-project.supabase.co');
putenv('SUPABASE_SECRET_KEY=server-only-secret');
putenv('SUPABASE_DOCUMENT_BUCKET=parish-documents');

// Render production should use database sessions after applying
// database/migration_sessions.sql in Supabase.
putenv('SESSION_DRIVER=files');
putenv('SESSION_GC_MAXLIFETIME=7200');

// Brevo (transactional email) — used to email guests (no account, so no
// in-app notification) when their appointment is approved/rescheduled/etc.
// See includes/mailer.php. Leave unset/placeholder to disable email sending.
putenv('BREVO_API_KEY=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');
putenv('BREVO_SENDER_EMAIL=your-verified-sender@example.com');
putenv('BREVO_SENDER_NAME=PARISHHUB');

// PayMongo hosted Checkout. Use a test secret key for local/test mode and
// keep this file uncommitted. The key is read server-side only by
// includes/paymongo.php; never place it in HTML or JavaScript.
putenv('PAYMONGO_SECRET_KEY=sk_test_replace_me');
putenv('PAYMONGO_WEBHOOK_SECRET=whsk_test_replace_me');
