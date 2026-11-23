<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_senscyreminders;

/** One evaluator serves recipient preview, export, and immediate email dispatch. */
final class service {
    const CAMPAIGNS = 'local_scr_campaigns';
    const LOG = 'local_scr_log';

    public static function lock() {
        return \core\lock\lock_config::get_lock_factory('local_senscyreminders')->get_lock('dispatch', 5);
    }

    public static function global_enabled(): bool {
        global $DB;
        // Read the database directly: a pause must affect an already running cron request.
        return (bool)$DB->get_field('config_plugins', 'value', ['plugin' => 'local_senscyreminders', 'name' => 'enabled']);
    }

    public static function platform_branch(): string {
        global $DB;
        $release = (string)$DB->get_field('config', 'value', ['name' => 'release']);
        return preg_match('/^\d+\.\d+/', $release, $match) ? $match[0] : '';
    }

    public static function runtime_reason(object $campaign, ?int $now=null): string {
        global $DB, $CFG;
        if ($DB->get_manager()->table_exists('senscyreminder')
                && $DB->record_exists('senscyreminder', ['course' => $campaign->courseid])) {
            $cm = $DB->get_record_sql("SELECT cm.* FROM {course_modules} cm JOIN {modules} m ON m.id=cm.module
                JOIN {senscyreminder} r ON r.id=cm.instance WHERE m.name='senscyreminder' AND r.course=:course",
                ['course' => $campaign->courseid], IGNORE_MULTIPLE);
            if (!$cm || $cm->deletioninprogress) { return 'activity_unavailable'; }
        }
        if (is_file($CFG->dataroot . '/climaintenance.html')
                || $DB->get_field('config', 'value', ['name' => 'maintenance_enabled'])
                || $DB->get_field('config', 'value', ['name' => 'upgraderunning'])) {
            return 'site_maintenance';
        }
        $branch = self::platform_branch();
        if (!$branch || empty($campaign->approvedbranch) || $campaign->approvedbranch !== $branch) {
            return 'platform_changed';
        }
        if (($campaign->logohash ?? '') !== assets::hash((int)$campaign->id)) {
            return 'logo_changed';
        }
        if (empty($campaign->approvedpolicyhash) || !hash_equals(delivery_policy::signature(),$campaign->approvedpolicyhash)) {
            return 'delivery_policy_changed';
        }
        return delivery_policy::reason($campaign,null,$now);
    }

    public static function activity_ids(object $campaign): array {
        return !empty($campaign->allh5p) ? array_map('intval', array_keys(self::activities((int)$campaign->courseid))) : rules::ids($campaign->cmids);
    }

    public static function stop_all(): void {
        global $DB;
        set_config('enabled', 0, 'local_senscyreminders');
        $DB->set_field(self::CAMPAIGNS, 'enabled', 0, []);
    }

    public static function pause_course(int $courseid): void {
        global $DB;
        $DB->execute('UPDATE {local_scr_campaigns} SET enabled=0,approvedhash=:empty WHERE courseid=:course',
            ['empty' => '', 'course' => $courseid]);
    }

    public static function manage_url(int $courseid): \moodle_url {
        global $DB;
        if ($DB->get_manager()->table_exists('senscyreminder')) {
            $cmid = $DB->get_field_sql("SELECT cm.id FROM {course_modules} cm JOIN {modules} m ON m.id=cm.module
                JOIN {senscyreminder} r ON r.id=cm.instance WHERE m.name='senscyreminder' AND r.course=:course
                AND cm.deletioninprogress=0", ['course' => $courseid], IGNORE_MULTIPLE);
            if ($cmid) { return new \moodle_url('/mod/senscyreminder/view.php', ['id' => $cmid]); }
        }
        return new \moodle_url('/local/senscyreminders/index.php', ['courseid' => $courseid]);
    }

    public static function activate(int $id, int $actor): void {
        global $DB;
        $lock = self::lock();
        if (!$lock) { throw new \moodle_exception('busy', 'local_senscyreminders'); }
        try {
            $campaign = $DB->get_record(self::CAMPAIGNS, ['id' => $id], '*', MUST_EXIST);
            if ($errors = self::validate($campaign)) {
                throw new \moodle_exception('invalidcampaign', 'local_senscyreminders', '', implode(' ', $errors));
            }
            if ($reason=delivery_policy::reason($campaign)) {
                throw new \moodle_exception('deliveryblocked','local_senscyreminders','',delivery_policy::describe().' '.$reason);
            }
            $usable = false;
            foreach (rules::schedule($campaign) as $date) {
                $dayend = (new \DateTimeImmutable('@' . $date['time']))->setTimezone(new \DateTimeZone($campaign->timezone))->modify('tomorrow')->setTime(0, 0)->getTimestamp();
                $usable = $usable || ($date['enabled'] && $dayend > time());
            }
            if (!$usable) { throw new \moodle_exception('nofuturedates', 'local_senscyreminders'); }
            $campaign->approvedbranch = self::platform_branch();
            $campaign->logohash = assets::hash($id);
            $campaign->approvedhash = rules::fingerprint($campaign);
            $campaign->approvedpolicyhash = delivery_policy::signature();
            $campaign->approvedby = $actor; $campaign->approvedat = time();
            $campaign->enabled = 1; $campaign->cursorid = 0; $campaign->cursorslot = -1;
            // This version controls a single training run. Resuming it cannot resurrect another stopped run.
            $DB->set_field_select(self::CAMPAIGNS, 'enabled', 0, 'id<>:id', ['id' => $id]);
            $DB->update_record(self::CAMPAIGNS, $campaign);
            set_config('enabled', 1, 'local_senscyreminders');
        } finally { $lock->release(); }
    }

    public static function activities(int $courseid): array {
        global $DB;
        return $DB->get_records_sql("SELECT cm.id, a.name, m.name AS modname
              FROM {course_modules} cm JOIN {modules} m ON m.id=cm.module
              JOIN {h5pactivity} a ON a.id=cm.instance
             WHERE cm.course=:course AND m.name='h5pactivity' AND a.enabletracking=1 AND cm.deletioninprogress=0 AND cm.completion=2
          ORDER BY a.name", ['course' => $courseid]);
    }

    public static function validate(object $campaign): array {
        global $DB;
        $errors = [];
        try {
            [$start, $end] = rules::window($campaign->period, $campaign->timezone);
            $dates = rules::schedule($campaign);
            $times = [];
            foreach ($dates as $date) {
                if (!isset($date['time'], $date['enabled']) || !is_int($date['time']) || !is_bool($date['enabled'])
                        || $date['time'] < $start->getTimestamp() || $date['time'] >= $end->getTimestamp()
                        || in_array($date['time'], $times, true)) {
                    throw new \InvalidArgumentException('Invalid sending date.');
                }
                $times[] = $date['time'];
            }
            if (!$dates || count($dates) > 5) {
                throw new \InvalidArgumentException('Invalid sending dates.');
            }
        } catch (\Exception $e) {
            $errors[] = 'Choose a valid first sending time, timezone and dates within one training month.';
        }
        $course = $DB->get_record('course', ['id' => $campaign->courseid]);
        if (!$course || $course->id == SITEID || !$course->enablecompletion) {
            $errors[] = 'Choose a course with completion tracking enabled.';
        }
        $allowed = self::activities((int)$campaign->courseid);
        $ids = self::activity_ids($campaign);
        if (!$ids || array_diff($ids, array_keys($allowed))
                || (!empty($campaign->allh5p) && array_diff(rules::ids($campaign->cmids), array_keys($allowed)))) {
            $errors[] = 'Select approved native H5P activities with automatic completion and attempt tracking enabled in this course.';
        }
        if (trim($campaign->subject) === '' || trim($campaign->body) === '') {
            $errors[] = 'Subject and message are required.';
        }
        foreach (['trainingurl', 'logourl'] as $field) {
            $url = $campaign->$field ?? '';
            if ($url !== '' && (strlen($url) > 1024 || !filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http','https'], true))) {
                $errors[] = 'Training and logo URLs must be full HTTP(S) URLs.';
            }
        }
        if (preg_match('~draftfile\.php|@@PLUGINFILE@@|src\s*=\s*["\']\s*data:~i', $campaign->body)) {
            $errors[] = 'Use the logo upload above the editor, or public image URLs, rather than draft or pasted-data images.';
        }
        return $errors;
    }

    public static function candidate_sql(object $campaign, ?int $now=null): array {
        global $DB;
        $context = \context_course::instance($campaign->courseid);
        [$enrolled, $params] = get_enrolled_sql($context, 'moodle/course:isincompletionreports', 0, true);
        $scope='';$params+=['scrguest'=>guest_user()->id];
        if (delivery_policy::read()['configured']) {
            if (delivery_policy::reason($campaign,null,$now)) { $scope=' AND 1=0'; }
            else {
                [$insql,$bound]=$DB->get_in_or_equal(delivery_policy::ids(),SQL_PARAMS_NAMED,'devuid');
                $scope=' AND u.id '.$insql;$params+=$bound;
            }
        }
        return ["SELECT u.* FROM {user} u JOIN ($enrolled) e ON e.id=u.id
                  WHERE u.deleted=0 AND u.suspended=0 AND u.id<>:scrguest".$scope, $params];
    }

    public static function candidates(object $campaign, int $after = 0, int $limit = 250, ?int $now=null): array {
        global $DB;
        [$sql, $params] = self::candidate_sql($campaign,$now);
        return $DB->get_records_sql($sql . ' AND u.id>:scrafter ORDER BY u.id', $params + ['scrafter' => $after], 0, $limit);
    }

    /** Fetch completion records directly, avoiding cached states left over from preview. */
    public static function evaluate(object $campaign, object $user, int $now, int $excluding = 0): array {
        global $DB;
        if (delivery_policy::read()['configured'] && delivery_policy::reason($campaign,$user,$now)) {
            return ['reason'=>'devtest_outside_scope'];
        }
        $course = $DB->get_record('course', ['id' => $campaign->courseid]);
        if (!$course || !$course->visible || !$course->enablecompletion) {
            return ['reason' => 'course_unavailable'];
        }
        $context = \context_course::instance($course->id);
        if ($user->deleted || $user->suspended || ($user->auth ?? '') === 'nologin'
                || !is_enrolled($context, $user->id, 'moodle/course:isincompletionreports', true)) {
            return ['reason' => 'ineligible'];
        }
        if ($user->emailstop || !validate_email($user->email) || str_ends_with(strtolower($user->email), '.invalid') || isguestuser($user)) {
            return ['reason' => 'email_unavailable'];
        }
        $ids = self::activity_ids($campaign);
        $available = self::activities($course->id);
        if (!$ids || array_diff($ids, array_keys($available))
                || (!empty($campaign->allh5p) && array_diff(rules::ids($campaign->cmids), array_keys($available)))) {
            return ['reason' => 'invalid_activities']; // Fail closed if an approved activity is deleted or changed.
        }
        [$start, $end] = rules::window($campaign->period, $campaign->timezone);
        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'cm');
        $params += ['userid' => $user->id, 'start' => $start->getTimestamp(), 'end' => $end->getTimestamp()];
        $record = $DB->get_records_sql("SELECT id,coursemoduleid,completionstate,timemodified
            FROM {course_modules_completion} WHERE userid=:userid AND coursemoduleid $insql
            AND completionstate IN (1,2) AND timemodified>=:start AND timemodified<:end
            ORDER BY timemodified DESC,id DESC", $params, 0, 1);
        if ($record) {
            $completion = reset($record);
            return ['reason' => 'completed', 'cmid' => $completion->coursemoduleid, 'time' => $completion->timemodified,
                'evidencekind' => 'moodle_completion', 'evidenceid' => $completion->id];
        }
        // A replay may not change an already-complete Moodle flag. Count a fresh top-level H5P
        // completion result, never an opened video, a sub-question, or a failed result.
        $retake = $DB->get_records_sql("SELECT r.id,cm.id AS cmid,r.timecreated
            FROM {h5pactivity_attempts_results} r
            JOIN {h5pactivity_attempts} a ON a.id=r.attemptid
            JOIN {course_modules} cm ON cm.instance=a.h5pactivityid
            JOIN {modules} m ON m.id=cm.module AND m.name='h5pactivity'
            JOIN {course_modules_completion} c ON c.coursemoduleid=cm.id AND c.userid=a.userid
            WHERE a.userid=:userid AND cm.id $insql AND c.completionstate IN (1,2)
            AND (r.subcontent='' OR r.subcontent IS NULL) AND r.completion=1
            AND (r.success=1 OR (r.success IS NULL AND COALESCE(r.maxscore,0)=0))
            AND r.timecreated>=:start AND r.timecreated<:end ORDER BY r.timecreated DESC,r.id DESC", $params, 0, 1);
        if ($retake) {
            $result = reset($retake);
            return ['reason' => 'completed', 'cmid' => $result->cmid, 'time' => $result->timecreated,
                'evidencekind' => 'h5p_result', 'evidenceid' => $result->id];
        }
        $last = $DB->get_records_select(self::LOG,
            'campaignid=:campaign AND id<>:excluding AND (userid=:user OR emailhash=:email) AND status IN (:a,:b,:c)',
            ['campaign' => $campaign->id ?? 0, 'user' => $user->id, 'email' => rules::emailhash($user->email),
                'a' => 'submitted', 'b' => 'held', 'c' => 'reserved', 'excluding' => $excluding], 'timecreated DESC', '*', 0, 1);
        if ($last) {
            $last = reset($last);
            $next = (new \DateTimeImmutable('@' . $last->timecreated))->setTimezone(new \DateTimeZone($campaign->timezone))->modify('+7 days');
            if ($next->getTimestamp() > $now) {
                return ['reason' => 'recent_reminder'];
            }
        }
        // Respect sections, profile restrictions, prerequisites and enrolment access as this learner.
        get_fast_modinfo($course, $user->id, true);
        $modinfo = get_fast_modinfo($course, $user->id);
        foreach ($ids as $cmid) {
            if ($modinfo->get_cm($cmid)->uservisible) {
                return ['reason' => 'remind'];
            }
        }
        return ['reason' => 'no_accessible_training'];
    }

    public static function render_message(object $campaign, object $user): array {
        [$subject, $plain] = message::render($campaign, $user);
        return [$subject, $plain];
    }

    public static function send(object $campaign, object $user, int $reservation=0): bool {
        global $CFG;
        $policy=delivery_policy::read();
        if (!empty($CFG->noemailever) || delivery_policy::reason($campaign,$user,null,$policy)
                || !delivery_policy::budget($campaign,$reservation)
                || (isset($campaign->approvedpolicyhash) && !hash_equals(delivery_policy::signature($policy),$campaign->approvedpolicyhash))) {
            return false;
        }
        [$subject, $body, $html] = message::render($campaign, $user);
        if ($policy['configured']) {
            // Belt and braces: original client address never reaches the mailer, even if diversion changes mid-call.
            $subject='[DEV TEST user '.(int)$user->id.'] '.$subject;
            $recipient=clone $user;
            $recipient->email=$policy['values']['devtestemail'];
            return email_to_user($recipient,\core_user::get_noreply_user(),$subject,$body,$html);
        }
        // Immediate Moodle email transport, not a second delayed notification queue.
        return email_to_user($user, \core_user::get_noreply_user(), $subject, $body, $html);
    }

    /** Send at most one bounded batch. Injected transports are only permitted in isolated CLI tests. */
    public static function process(int $id, ?int $now = null, ?callable $transport = null): array {
        global $DB, $CFG;
        if ($transport !== null && !(defined('LOCAL_SCR_TESTING') && defined('CLI_SCRIPT') && CLI_SCRIPT)) {
            throw new \coding_exception('Injected transport is only available to CLI integration tests.');
        }
        $fixednow = $now;
        $now = $now ?? time();
        $totals = ['submitted' => 0, 'suppressed' => 0, 'held' => 0];
        $lock = self::lock();
        if (!$lock) {
            return $totals;
        }
        try {
            $campaign = $DB->get_record(self::CAMPAIGNS, ['id' => $id], '*', MUST_EXIST);
            $slot = rules::slot($campaign, $now);
            if (!self::global_enabled() || !$campaign->enabled || $slot === null || self::validate($campaign)
                    || !hash_equals(rules::fingerprint($campaign), $campaign->approvedhash) || self::runtime_reason($campaign,$now)) {
                return $totals;
            }
            if ($transport === null && !empty($CFG->noemailever)) {
                return $totals; // Never mark an email submitted when the site blocks or diverts it.
            }
            if ($transport === null && $DB->is_transaction_started()) {
                throw new \coding_exception('Email dispatch must run outside a database transaction.');
            }
            $cursor = (int)$campaign->cursorslot === $slot ? (int)$campaign->cursorid : 0;
            $limit = max(1, min(1000, (int)get_config('local_senscyreminders', 'batchsize') ?: 250));
            $began = microtime(true);
            foreach (self::candidates($campaign, $cursor, $limit,$now) as $candidate) {
                // Pause/config/completion checks occur inside the dispatch loop, not at queue creation.
                $fresh = $DB->get_record(self::CAMPAIGNS, ['id' => $id], '*', MUST_EXIST);
                if (!self::global_enabled() || !$fresh->enabled || !hash_equals($campaign->approvedhash, $fresh->approvedhash)
                        || !hash_equals(rules::fingerprint($fresh), $fresh->approvedhash)
                        || self::runtime_reason($fresh,$fixednow??time())
                        || rules::slot($fresh, $fixednow ?? time()) !== $slot) {
                    break;
                }
                $user = $DB->get_record('user', ['id' => $candidate->id], '*', MUST_EXIST);
                $decision = self::evaluate($fresh, $user, $now);
                $existing = $DB->record_exists(self::LOG, ['campaignid' => $id, 'userid' => $user->id, 'slot' => $slot]);
                $emailhash = rules::emailhash($user->email);
                $sameaddress = $DB->record_exists_select(self::LOG,
                    'campaignid=:c AND slot=:s AND emailhash=:e AND status IN (:reserved,:submitted,:held)',
                    ['c' => $id, 's' => $slot, 'e' => $emailhash, 'reserved' => 'reserved', 'submitted' => 'submitted', 'held' => 'held']);
                if (!$existing) {
                    if ($decision['reason']==='remind' && !$sameaddress && !delivery_policy::budget($fresh)) { break; }
                    $row = (object)['campaignid' => $id, 'userid' => $user->id, 'slot' => $slot,
                        'emailhash' => $emailhash, 'status' => 'suppressed',
                        'reason' => $decision['reason'], 'evidencecmid' => $decision['cmid'] ?? 0,
                        'evidencekind' => $decision['evidencekind'] ?? '', 'evidenceid' => $decision['evidenceid'] ?? 0,
                        'evidencetime' => $decision['time'] ?? 0, 'timecreated' => $fixednow ?? time(), 'processedat' => $fixednow ?? time()];
                    if ($sameaddress) {
                        $row->reason = 'duplicate_address';
                    } else if ($decision['reason'] === 'remind') {
                        $row->status = 'reserved';
                    }
                    $row->id = $DB->insert_record(self::LOG, $row);
                    if ($row->status === 'reserved') {
                        // Reservation is durable before external I/O. A crash leaves it held, never auto-retried.
                        try {
                            $finalcampaign = $DB->get_record(self::CAMPAIGNS, ['id' => $id], '*', MUST_EXIST);
                            $finaluser = $DB->get_record('user', ['id' => $user->id], '*', MUST_EXIST);
                            // Exclude this just-created reservation from the fresh evaluation's cadence check.
                            $finaldecision = self::evaluate($finalcampaign, $finaluser, $fixednow ?? time(), $row->id);
                            if (!self::global_enabled() || !$finalcampaign->enabled
                                    || !hash_equals($campaign->approvedhash, $finalcampaign->approvedhash)
                                    || !hash_equals(rules::fingerprint($finalcampaign), $finalcampaign->approvedhash)
                                    || self::runtime_reason($finalcampaign,$fixednow??time())
                                    || rules::slot($finalcampaign, $fixednow ?? time()) !== $slot) {
                                $row->status = 'suppressed';
                                $row->reason = 'paused_or_changed';
                            } else if ($finaldecision['reason'] !== 'remind' || $finaluser->email !== $user->email) {
                                $row->status = 'suppressed';
                                $row->reason = $finaldecision['reason'] !== 'remind' ? $finaldecision['reason'] : 'profile_changed';
                                $row->evidencecmid = $finaldecision['cmid'] ?? 0;
                                $row->evidencetime = $finaldecision['time'] ?? 0;
                                $row->evidencekind = $finaldecision['evidencekind'] ?? '';
                                $row->evidenceid = $finaldecision['evidenceid'] ?? 0;
                            } else {
                                $ok = $transport ? $transport($finalcampaign, $finaluser) : self::send($finalcampaign, $finaluser,$row->id);
                                $row->status = $ok ? 'submitted' : 'held';
                                $row->reason = $ok ? (delivery_policy::read()['configured'] ? 'dev_diversion_accepted' : 'transport_accepted') : 'transport_unconfirmed';
                            }
                        } catch (\Throwable $e) {
                            $row->status = 'held';
                            $row->reason = 'transport_exception';
                            debugging('SensCy reminder transport failed; delivery held for review.', DEBUG_DEVELOPER);
                        }
                        $row->processedat = $fixednow ?? time();
                        $DB->update_record(self::LOG, $row);
                    }
                    $key = $row->status === 'submitted' ? 'submitted' : ($row->status === 'held' ? 'held' : 'suppressed');
                    $totals[$key]++;
                }
                $DB->set_field(self::CAMPAIGNS, 'cursorid', $user->id, ['id' => $id]);
                $DB->set_field(self::CAMPAIGNS, 'cursorslot', $slot, ['id' => $id]);
                if (microtime(true) - $began > 45) {
                    break;
                }
            }
            return $totals;
        } finally {
            $lock->release();
        }
    }
}
