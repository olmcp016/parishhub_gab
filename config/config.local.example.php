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

// Brevo (transactional email) — used to email guests (no account, so no
// in-app notification) when their appointment is approved/rescheduled/etc.
// See includes/mailer.php. Leave unset/placeholder to disable email sending.
putenv('BREVO_API_KEY=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');
putenv('BREVO_SENDER_EMAIL=your-verified-sender@example.com');
putenv('BREVO_SENDER_NAME=PARISHHUB');
