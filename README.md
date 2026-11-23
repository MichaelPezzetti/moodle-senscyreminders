# SensCy training reminders for Moodle

A **Training reminders** course activity backed by one reminder engine. Administrators prepare a message, review weekly sending dates through month-end, remove unwanted dates and start manually. A qualifying native H5P completion in the same course/month suppresses further reminders.

**Beta build for DEV/staging review.** Tested on Moodle 4.5.16 and 5.2.5; see [validation and limits](VALIDATION.md). Verify your own H5P completion criteria, real hosting cron and SMTP with internal accounts before client rollout.

## Download and install

Install these **two plugins once, in this order**:

1. [Reminder engine — local_senscyreminders 0.4.0](dist/local_senscyreminders-0.4.0.zip?raw=1)
2. [Course activity — mod_senscyreminder 0.1.0](dist/mod_senscyreminder-0.1.0.zip?raw=1)

Or [download the complete bundle](dist/SensCy-Reminders-Activity-Bundle-0.4.0.zip?raw=1), extract it, and install its two ZIPs separately. **Do not upload the outer bundle or GitHub's source-code ZIP to Moodle's plugin installer.**

Follow [INSTALL.md](INSTALL.md). Then, in the existing training course, choose **Add an activity or resource → Training reminders**. Keep it hidden from students. Save/display opens the course-specific controls. Reuse this one activity for later training months.

Sending starts OFF. Updating the engine or adding/editing/deleting the activity pauses affected sending for review. The activity adds no second email scheduler.

## Existing DEV installation

If the activity is already installed at 0.1.0, upgrade **only the engine to 0.4.0** and complete Moodle Notifications. The upgrade preserves messages/history and pauses all sending for fresh review.

**Keep global email diversion enabled for DEV.** Engine Settings now offer bounded DEV diversion testing: exact site root, matching single mailbox with no diversion exceptions, one course ID, 1–10 allowed learner IDs, and an expiry within 48 hours (America/New_York). The preview and dispatch use the allowlist. Test messages retain learner names but are explicitly addressed to the test mailbox, tagged `[DEV TEST user <id>]`, and limited to ten attempts per run. Missing diversion or changed settings blocks sending; noemailever remains respected. Saving settings requires explicit review/resume. See the [engine README](local/senscyreminders/README.md#bounded-dev-diversion-testing-040).

## Behavior

- Edit HTML messages, logo and hyperlinks; personalize with `{firstname}`, `{lastname}` and `{courselink}`.
- Review exact weekly dates in the chosen timezone. No automatic off-month starts or catch-up batches on missed days.
- Any qualifying tracked native H5P completion during the run's calendar month stops further reminders. Certificates, pages and this administrative activity do not count.
- Preview/export recipient decisions before starting; completion and eligibility are checked again immediately before submission.
- Pause or **Stop All** to stop later submissions. **Hiding the activity only changes visibility; it does not stop email.**
- Retain duplicate-protection history when editing or deleting the activity. Unknown delivery outcomes are not automatically retried for the same sending date.
- Moodle branch changes (for example 4.5 to 5.2), maintenance and pending activity deletion hold sending for review.

Recipients come from eligible active course enrolments and the learners' actual H5P access. Restrictions on this administrative activity do not select recipient groups. Administration is site-admin-only by default.

Disable overlapping legacy Re-engagement/reminder automation separately. Stop All cannot recall mail already handed to a mail server. Remote logos can be blocked by email clients.

## Source layout

| Directory | Moodle component | Version |
| --- | --- | --- |
| `local/senscyreminders` | `local_senscyreminders` | 0.4.0 / 2026112300 |
| `mod/senscyreminder` | `mod_senscyreminder` | 0.1.0 / 2026111600 |
| `dist` | Installable ZIPs and SHA-256 checksums | Current reviewed build |

Copy these plugin directories into the Moodle web root. In Moodle 5.2's split source tree, that web root is `public/`. No Moodle core files, site configuration, customer exports, database dumps, credentials or production logs are included here.

Activity backup/restore/duplication is intentionally unsupported. After restoring a course, add a fresh administrative activity and review its run; live reminder settings are not copied.

## Tests

Pure rules (no Moodle bootstrap required):

```sh
php local/senscyreminders/tests/run_rules.php
```

After installing both components in a **localhost-only test Moodle** with `$CFG->noemailever = true`:

```sh
php local/senscyreminders/tests/local_integration.php /absolute/path/to/moodle-webroot
```

The integration harness creates synthetic fixtures, uses fake transport and rolls back its database changes. It rejects non-local environments. The packaged build passed 39 pure assertions and 100 integration assertions per test site. Separate loopback SMTP captures verified the rendered email on both target Moodle versions; details and limitations are in [VALIDATION.md](VALIDATION.md).

## Moodle hosting review

Please review the source/dependencies and install on DEV first, with sending disabled. Confirm the site's PHP/database compatibility, actual H5P completion evidence, scheduled task execution and controlled internal mail delivery. Production installation remains a separate step after successful testing.

License: GNU GPL v3 or later; see [LICENSE](LICENSE).
