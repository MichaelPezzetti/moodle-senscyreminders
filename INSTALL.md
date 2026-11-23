# Training reminders activity — installation and testing

This bundle contains two Moodle plugin ZIPs. **Extract this bundle first. Do not upload the outer bundle to Moodle's plugin installer.**

1. Install or upgrade **local_senscyreminders-0.4.0.zip** using Site administration > Plugins > Install plugins, then complete Notifications.
2. Install **mod_senscyreminder-0.1.0.zip**, then complete Notifications.

If the activity plugin is already installed at 0.1.0, keep it and upgrade only the engine to 0.4.0. There is no need to reinstall the same activity version.

3. In your existing training course, turn editing on and choose **Add an activity or resource > Training reminders**.
4. Keep the activity hidden from students and save/display. One activity manages this course's reminder runs; do not create an activity for each video or month.
5. Click **Prepare run**, edit the message/logo/link, choose the first sending time and timezone, and save. Review the weekly dates through month-end and uncheck any you do not want.
6. Inspect the personalized email and recipient preview/CSV. Click **Start / Resume** only after the internal test is correct and overlapping legacy reminders are disabled.

The two plugins are installed once. Day to day, you work from the activity. The activity reuses the tested engine and creates no second sending task or notification queue.

## What is included

- Your logo URL and course-14 training link are prefilled for new runs.
- Rich-text message with dynamic first/last names and clickable training link.
- One reminder per checked weekly sending date, ending at month-end. Missed days are skipped.
- Qualifying H5P completion in the same course/month suppresses further reminders. Certificates and this administrative activity do not count as training.
- Stop All, per-run Pause, duplicate protection, recipient preview and retained history.
- Moodle 4.5-to-5.2 branch-change holds until review/resume; retain both plugins and their database tables during upgrades.

## Activity behavior

- New activities are hidden by default. Management is administrator-only by default; students cannot open its controls.
- **Hidden means hidden from learners. It does not stop sending. Use Pause or Stop All to stop email.**
- Adding/updating the activity pauses existing reminders for its course. It never starts mail automatically.
- Deleting the activity pauses its course's runs and retains send history. A pending background deletion also blocks dispatch.
- One activity per course is enforced. Backup/restore/duplicate support is deliberately disabled for this administrative activity; after restoring a course, add a fresh activity and review its new run. Existing H5P/certificate backup behavior is unchanged.
- The administrative activity's access restrictions do not select recipient groups. The engine uses active learner enrolments and actual H5P training access, with the agreed course-wide monthly completion rule.

## Before client use

Use DEV first. Automated tests and a local-only SMTP capture verify the implemented behavior, not your production SMTP provider or real H5P package criteria. Complete a real H5P with internal accounts, check the recipient decisions, and test your host's actual cron and mail delivery before enabling client recipients.

Disable overlapping Re-engagement or other reminder automation. Installing this activity does not stop another plugin's queued messages. Stop All cannot recall a message already handed to the mail server. Some email clients block remote logos or prefer plain text.

The local XAMPP site retains its email guard and sending OFF. Production has not been modified.


## Testing with diverted email

Engine 0.4.0 adds **Bounded DEV diversion testing** under the controls’ Settings link. Keep global Moodle diversion enabled with no exceptions. Configure the exact DEV site root, matching single diversion mailbox, course ID, 1–10 allowed learner IDs and an expiry within 48 hours (America/New_York). Review the preview and explicitly Start / Resume after saving. Only the selected learners are considered; completed learners are suppressed normally. Test messages use the learner’s names but are explicitly addressed to the test inbox and tagged `[DEV TEST user <id>]`. Maximum ten attempts per run. Missing diversion or changed settings stop sending; there is no silent fallback to client addresses. See the engine README for the complete policy.

Upgrading the engine pauses all sending and revokes prior approval while preserving messages and history. Keep the existing activity at 0.1.0. This update must be installed on DEV before these test settings exist.
