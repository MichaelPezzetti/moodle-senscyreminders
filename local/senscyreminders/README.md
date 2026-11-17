# SensCy training reminders engine 0.3.1

A standalone Moodle local plugin for manually started training reminder runs. Uses your existing course and native H5P activities (`h5pactivity`). No group-to-video mapping is needed. It does not change completions, certificates or course access restrictions.

This engine also powers the **Training reminders** course activity (`mod_senscyreminder`). Install the activity ZIP after this engine, then add one activity to your course. The activity contains the course-specific controls below and reuses this engine’s single scheduler. Adding, editing or deleting the activity pauses its course’s runs. Hiding it only changes learner visibility.

## Your workflow

1. Open **Training reminders** in the course navigation, or **Site administration > Plugins > Local plugins > SensCy training reminders > Manage training reminder runs**.
2. Choose your course and **Prepare run**. For later training months, **Prepare next training run using previous message** copies the message, logo and link into a paused draft.
3. Choose the first sending date/time and timezone. Weekly dates run from that point through month-end. The form suggests your shared even-month calendar, but you can edit the date.
4. Edit the subject and rich-text message. Upload a logo or enter a public logo URL. Default training link: https://cyberhealth.senscy.com/course/view.php?id=14 .
5. Save, review the personalized HTML example and recipient preview/CSV, and uncheck unwanted sending dates. **Save selected sending dates** pauses the run.
6. Check the confirmation and click **Start / Resume reviewed run**. This enables sending for that run and pauses any other run.
7. Use **Pause** for one run, or the red **Stop ALL reminders** button for everything. A message already handed to the mail server cannot be recalled.

Nothing automatically starts every other month. You start each training month's run yourself, with a reusable message. Past months retain send history. One record per course/month prevents duplicate runs; edit the existing record.

## Completion and recipients

An eligible employee stops receiving reminders after a qualifying completion of **any automatically tracked native H5P activity in that course during the training month**, in the run's timezone. New tracked H5P activities are included automatically.

Qualifying evidence is either:

- Moodle activity completion state 1 (complete) or 2 (complete/pass), with `timemodified` inside the month.
- A fresh top-level H5P result in the month with completion=1 and success=1, while current Moodle completion remains 1 or 2. A non-scored top-level result may qualify with success unset and maxscore=0. This supports retakes whose old Moodle completion timestamp does not change.

Previous-month completions, opened videos, subquestions, failed attempts, certificates, pages and other activity types do not qualify. Third-party `hvp` activities are not supported. H5P needs attempt tracking and automatic Moodle completion. Correctly configure the content's completion/pass criteria: the plugin relies on recorded outcomes, not independent video-watch detection. Manual overrides/imports can affect Moodle timestamps; their evidence is accepted and shown in the CSV.

Recipients need active learner enrolment and `moodle/course:isincompletionreports` (normally students), a visible course, and access to at least one qualifying training under their real group/profile/section/prerequisite restrictions. Deleted/suspended/guest users, disabled email and invalid addresses are excluded. Preview and dispatch share the evaluator. Eligibility and completion are checked again immediately before transport submission. New eligible enrolments can join later; approval does not freeze an address list.

## Message and logo

Use `{firstname}`, `{lastname}`, `{coursename}`, `{courselink}` and `{month}`. Legacy `%userfirstname%` / `%userlastname%` also work. Names are safely escaped in HTML. Hyperlink **Click training here** to your training URL using the editor. HTML and plain-text alternatives are generated; Moodle respects each employee's email-format preference.

Upload one PNG, JPEG or GIF up to 512 KB and 4000 pixels per dimension. Uploaded logos use a **public branding-only image URL**, so email clients need no Moodle session. Do not upload private information. Email clients may block remote images until the recipient allows them. Other message images require public URLs; private draft images and pasted base64 images are rejected.

## Sending dates and failsafes

- Dates are weekly from the exact local first-send time you choose through month-end. Daylight saving changes preserve local clock time.
- Cron must run regularly, normally every minute. A listed date is eligible after its sending time and before local midnight. Missed days are **skipped**, never caught up later. No sending after the month closes.
- Batches process up to 250 learners / approximately 45 seconds by default, with a saved cursor. Batch size is configurable from 1 to 1000. Large audiences need enough time before midnight.
- A minimum gap of seven local calendar days applies after submitted/held/reserved messages, including shared addresses. A late submission can suppress the next scheduled date.
- Edits and date changes pause the run and revoke approval. Removed dates retain stable keys so prior submissions are never renumbered.
- Removing or disabling tracking/completion on an approved H5P holds sending for review, even when other H5Ps remain. Newly added tracked H5Ps are included automatically.
- Stop All turns off the master switch and pauses every run. Start / Resume enables only the reviewed run.
- Maintenance mode or an upgrade in progress blocks sending. A Moodle branch change, for example 4.5 to 5.2, blocks existing approvals until you review and resume. Retain the plugin and its database tables across the core upgrade.
- The plugin refuses live submission if Moodle blocks or diverts mail. Local XAMPP retains `noemailever=true`; this plugin does not remove it.

The plugin uses Moodle's immediate `email_to_user()` transport after final checks. Durable unique learner/run/sending-date reservations and shared-address checks prevent automatic duplicate attempts. `submitted` means transport accepted the message, not confirmed inbox delivery. `held` or `reserved` means delivery is uncertain and is never automatically retried for that date. Review provider logs before any manual resend. A completion arriving after the final check cannot recall a submitted message.

## Installation and upgrades

Install `local_senscyreminders-0.3.1.zip` using Moodle's plugin installer, then visit **Notifications**. Manual location: `local/senscyreminders` under the Moodle 4.5 web root, or `public/local/senscyreminders` in Moodle 5.2's source tree. Requires Moodle 4.5+ and native H5P. PHP/database requirements follow the Moodle version.

Upgrading to 0.3 pauses all sending for review. Upgrading from 0.1 preserves configuration and history, converts weekly indexes to date keys, and pauses all sending for review. Existing explicit H5P pools remain until the run is edited with the new form, which adopts the course-wide H5P rule.

Use staging first. Disable overlapping Re-engagement/other reminders before activation: this plugin cannot stop another plugin's queued mail. Verify actual H5P content, sample accounts and the recipient CSV. Test real HTML/logo delivery in an internal-only staging course before client rollout. Automated tests do not establish actual SMTP delivery.

Management requires `local/senscyreminders:manage`, implicitly available to site administrators only. Mutations require session keys. CSV fields are protected against spreadsheet formula execution. Privacy export/deletion is supported; deleting history pauses affected runs.

## Tests

`php local/senscyreminders/tests/run_rules.php`

`php local/senscyreminders/tests/local_integration.php /absolute/path/to/moodle-webroot`

Integration tests require localhost site/database and `noemailever=true`, use synthetic fixtures and fake transport, and roll back database changes. See VALIDATION.md for executed checks and limits.

License: GNU GPL v3 or later. See COPYING.txt.
