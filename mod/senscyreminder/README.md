# Training reminders activity 0.1.0

An administrative course activity for the SensCy reminders engine. Requires `local_senscyreminders` 0.3.0 (2026111601) or newer. Install the engine first, then this module.

Add **Training reminders** from the course activity chooser. Save/display opens the course's reminder controls. Prepare a month, edit the message, preview recipients and weekly dates, remove unwanted dates, then Start. Reuse this activity each training month.

Hidden from students by default. Administrator access only by default. Hiding is visibility, not a sending switch: use Pause or Stop All. Adding/editing/deleting the activity pauses its course's reminders; pending deletion blocks dispatch. History stays in the engine. There is no separate scheduler in this module.

This activity cannot count as a training completion. Only qualifying automatically tracked native H5P completions in the course/month count. No training, certificate or completion records are reset by this module.

The administrative activity's own access restrictions do not select reminder recipients. The engine uses eligible course enrolments and each learner's actual H5P training access.

Backup/restore/duplication is not supported, to avoid copying active reminder settings. Add a fresh activity after restoring a course. The local engine's privacy provider handles recipient decisions/approvals; this activity stores only course/name/configuration timestamps.

See the installation guide and validation report supplied with the activity bundle. Test internal accounts and the real hosting mail/cron environment before client rollout.

GPL v3 or later; see COPYING.txt.
