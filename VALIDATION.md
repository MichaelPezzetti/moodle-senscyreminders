# Activity build validation — November 17, 2026

Components: local_senscyreminders 0.3.1 (2026111700), mod_senscyreminder 0.1.0 (2026111600).
Environment: PHP 8.3.36 / MariaDB 10.11.19 / local Windows XAMPP.

## Executed checks

- PHP syntax checks across all 30 PHP files.
- 39 scheduling/completion assertions, including explicit local weekly dates, removed dates, DST, skipped missed days, month-end cutoff and stable duplicate-protection keys.
- 73 integration assertions on Moodle 4.5.16 (Build: 20261112), 5.2.5 (Build: 20261112), and the original local 4.5.15+ sandbox. Fixture database changes rolled back.
- 48 engine assertions cover completion/pass versus fail, monthly evidence and H5P retakes, active enrolment/access/suspension, completion changing after preview, duplicate/shared-address protection, reservations/uncertain transport, Stop All, upgrade/maintenance-related guards, safe personalized HTML and logo handling.
- Activity checks use Moodle's actual add/delete activity APIs, reject duplicate activities, deny learner access, render the activity form and full controller/controls, isolate runs to the activity's course, verify hidden administrative activities can process approved reminders, hold pending background deletion, pause on update/delete, retain history, and ensure exactly one engine scheduler with no activity scheduler.
- On both 4.5.16 and 5.2.5, native Moodle email_to_user/PHPMailer submitted one message to a localhost-only SMTP capture server. Fourteen MIME/content assertions verified recipient, personalized subject/names, HTML logo above the message, correct clickable training URL and a readable plain-text URL alternative.
- Browser walkthrough on November 17: saved a LOCAL TEST run, confirmed the logo loaded and the names/link rendered, removed a weekly date and verified it stayed removed, started the run with the global mail guard still enabled, then used Stop All and verified the run was paused with zero submission logs.
- Version 0.3.1 fixes locale-dependent removal of leading zeros from the default date input. Regression checks cover the first day of a month and strict date round-tripping; the corrected default also passed the real browser save workflow.
- No external or client email was sent. The SMTP test overrides email settings only in the isolated test PHP process; saved test/main config files retain their mail guards. The original local site remains sending OFF.

The earlier populated v0.1-to-v0.2 migration test preserved messages and all send-history records while pausing sending. This activity release also passed 3 populated v0.2-to-v0.3 upgrade assertions, preserving the message/history and revoking sending. Engine upgrades and fresh module installation passed on all three sites. No existing H5P training, certificate, enrolment or completion records were changed by the plugin.

## Limits before production

These are synthetic completion fixtures and local SMTP captures, not a real employee completing your actual H5P package or a delivery test through your production provider. Verify real H5P completion settings and real hosting cron/SMTP with internal accounts before enabling client recipients. Disable overlapping legacy reminders separately. Email clients can block remote logo images or choose plain text.

The branch-change hold was tested by simulating the database-reported Moodle branch change. A complete production core upgrade from 4.5 to 5.2 was not performed. Retain both plugins/tables during upgrades, review and resume explicitly. A message already handed to the mail server cannot be recalled.

Both components are beta builds for controlled DEV/staging rollout. Activity backup/restore/duplication is intentionally unsupported, preventing accidental copying of active reminder settings. Add a fresh administrative activity after restoring a course.
