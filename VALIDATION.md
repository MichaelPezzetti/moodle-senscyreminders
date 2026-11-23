# Build validation — November 23, 2026

Components: local_senscyreminders 0.4.0 (2026112300), mod_senscyreminder 0.1.0 (2026111600).
Environment: PHP 8.3.36 / MariaDB 10.11.19 / Windows XAMPP.

## Executed checks

- Syntax checked every PHP file in both plugin directories.
- 39 pure scheduling/completion assertions: weekly local dates, date formatting, DST, removed dates, month-end and missed-day handling.
- 100 integration assertions on Moodle 4.5.16 (Build: 20261112), 5.2.5 (Build: 20261112), and the populated local 4.5.15+ sandbox. Synthetic fixture database changes rolled back, fake transport only.
- Existing completion/pass/fail and retake evidence checks, active enrolment/access, final completion rechecks, duplicate/shared-address protection, durable reservations, uncertain delivery, Stop All, platform/maintenance guards, personalized HTML/logo handling and real activity lifecycle/controller checks remained covered.
- 27 new DEV policy assertions cover restricted preview/dispatch, completed-learner suppression, non-allowlisted users, wrong site/course, invalid/too many IDs, expired or overly distant expiry, changed/removed diversion, diversion exceptions, disabled mode, changed approval, mid-batch stopping and the ten-attempt budget including held attempts. Clearing DEV settings cannot silently turn a previously approved test run into live sending.
- On both 4.5.16 and 5.2.5, native email_to_user/PHPMailer sent one normal and one DEV-mode message to a localhost-only SMTP capture server. 24 MIME/content/guard assertions checked the destination, original learner names, logo, link, plain text and DEV subject tagging. 16 negative native-transport guards rejected unsafe settings. The SMTP envelope check verified all four RCPT commands targeted only the capture mailbox; no original client-like address reached SMTP.
- Five populated upgrade assertions preserved message, history and schedule while revoking master/run sending and delivery-policy approval. The new engine schema upgrade completed on all three sites.
- Local saved mail guards remained enabled. Main local sending remained OFF. No external or client email was sent.

## DEV test design

Default OFF. Runtime admin settings bind a test to the exact installation root, single matching global diversion mailbox with no exceptions, one course, 1–10 allowed learner IDs and an expiry within 48 hours. A clone of each original learner is addressed explicitly to the fixed mailbox before invoking Moodle's mailer. Approval includes a digest of these settings; changes require explicit review/resume. Maximum ten reserved/submitted/held attempts per run. noemailever is always respected. Upgrade pauses all runs without deleting history. Disabling the checkbox does not fall back to client addresses.

## Limits before production

The tests use synthetic completion evidence and loopback SMTP, not a real employee completing the customer's H5P package or hosted provider delivery. Confirm actual H5P completion settings, hosted cron, diversion and inbox arrival on DEV before client use. Disable overlapping legacy reminders separately. A message already handed to the mail server cannot be recalled. A completion arriving after the final check cannot recall submitted mail. Remote logos can be blocked by email clients.

A complete core upgrade from 4.5 to 5.2 was not performed; independent installed versions and the branch-change hold were tested. Retain both plugins/tables during upgrades and review/resume explicitly. Both components remain beta builds for controlled DEV/staging review. Activity backup/restore/duplication remains unsupported to prevent copying active reminder settings.
