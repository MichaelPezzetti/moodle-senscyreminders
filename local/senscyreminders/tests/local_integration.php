<?php
/** CLI-only integration test. All fixture rows are rolled back. No real email transport. */
define('CLI_SCRIPT', true);
define('LOCAL_SCR_TESTING', true);
$moodle = $argv[1] ?? '';
if (!is_file($moodle . '/config.php')) {
    throw new RuntimeException('Usage: php local_integration.php C:/xampp/htdocs/moodle');
}
require $moodle . '/config.php';
if (empty($CFG->noemailever) || !in_array(parse_url($CFG->wwwroot, PHP_URL_HOST), ['localhost', '127.0.0.1'], true)
        || !in_array($CFG->dbhost, ['localhost', '127.0.0.1'], true)) {
    throw new RuntimeException('Only a localhost database/site with noemailever=true may run this fixture test.');
}
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/group/lib.php');
require_once($CFG->libdir . '/completionlib.php');
$CFG->debug = DEBUG_DEVELOPER;
$CFG->debugdisplay = true;
use local_senscyreminders\rules;
use local_senscyreminders\service;
$assertions = 0;
$check = function($condition, $message) use (&$assertions) {
    if (!$condition) { throw new RuntimeException($message); }
    $assertions++;
};
$tx = $DB->start_delegated_transaction();
$failure = null;
$courseid = 0;
try {
    $stamp = 'scr' . bin2hex(random_bytes(5));
    $category = $DB->get_field('course_categories', 'id', [], IGNORE_MULTIPLE);
    $course = create_course((object)['category' => $category, 'shortname' => $stamp, 'fullname' => 'Reminder fixture ' . $stamp,
        'format' => 'topics', 'visible' => 1, 'enablecompletion' => 1]);
    $courseid = $course->id;
    $context = context_course::instance($course->id);
    $studentrole = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
    assign_capability('mod/h5pactivity:view', CAP_ALLOW, $studentrole, $context->id);
    assign_capability('moodle/course:isincompletionreports', CAP_ALLOW, $studentrole, $context->id);
    $manual = enrol_get_plugin('manual');
    $enrolid = $DB->get_field('enrol', 'id', ['courseid' => $course->id, 'enrol' => 'manual']);
    if (!$enrolid) { $enrolid = $manual->add_instance($course); }
    $instance = $DB->get_record('enrol', ['id' => $enrolid], '*', MUST_EXIST);
    $DB->set_field('enrol', 'status', ENROL_INSTANCE_ENABLED, ['id' => $enrolid]);
    $instance->status = ENROL_INSTANCE_ENABLED;
    $groupid = (int)groups_create_group((object)['courseid' => $course->id, 'name' => 'Fixture access']);
    $users = [];
    foreach (['old','pass','complete','fail','pageonly','suspended','emailstop','unenrolled','restricted'] as $i => $label) {
        $record = (object)['auth' => 'manual', 'confirmed' => 1, 'mnethostid' => $CFG->mnet_localhost_id,
            'username' => $stamp . $label, 'password' => 'not cached', 'firstname' => 'Fixture', 'lastname' => $label,
            'email' => $stamp . $label . '@example.test', 'lang' => 'en', 'timezone' => '99',
            'deleted' => 0, 'suspended' => (int)($label === 'suspended'), 'emailstop' => (int)($label === 'emailstop'),
            'timecreated' => time(), 'timemodified' => time()];
        $record->id = $DB->insert_record('user', $record);
        if ($label !== 'unenrolled') { $manual->enrol_user($instance, $record->id, $studentrole, 0, 0, ENROL_USER_ACTIVE); }
        if ($label !== 'restricted') { groups_add_member($groupid, $record->id); }
        $users[$label] = $DB->get_record('user', ['id' => $record->id], '*', MUST_EXIST);
    }
    $makeactivity = function($modname, $name) use ($DB, $course, $groupid) {
        if ($modname === 'h5pactivity') {
            $activityid = $DB->insert_record('h5pactivity', (object)['course' => $course->id, 'name' => $name,
                'intro' => '', 'introformat' => FORMAT_HTML, 'timecreated' => time(), 'timemodified' => time(),
                'enabletracking' => 1, 'grade' => 100]);
        } else {
            $activityid = $DB->insert_record('page', (object)['course' => $course->id, 'name' => $name,
                'intro' => '', 'introformat' => FORMAT_HTML, 'content' => 'Fixture', 'contentformat' => FORMAT_HTML,
                'timemodified' => time()]);
        }
        $module = $DB->get_field('modules', 'id', ['name' => $modname], MUST_EXIST);
        $cmid = $DB->insert_record('course_modules', (object)['course' => $course->id, 'module' => $module,
            'instance' => $activityid, 'added' => time(), 'visible' => 1, 'visibleold' => 1,
            'completion' => COMPLETION_TRACKING_AUTOMATIC, 'availability' => json_encode([
                'op' => '&', 'c' => [['type' => 'group', 'id' => $groupid]], 'showc' => [true]])]);
        course_add_cm_to_section($course, $cmid, 1);
        return [$cmid, $activityid];
    };
    [$cmid, $h5pid] = $makeactivity('h5pactivity', 'Fixture training');
    [$pageid] = $makeactivity('page', 'Unapproved page');
    rebuild_course_cache($course->id, true);
    $now = (new DateTimeImmutable('2026-12-08 09:00:00', new DateTimeZone('America/New_York')))->getTimestamp();
    $inside = $now - 3600;
    $old = (new DateTimeImmutable('2026-11-15 12:00:00', new DateTimeZone('America/New_York')))->getTimestamp();
    $completion = function($user, $moduleid, $state, $at) use ($DB) {
        $row = $DB->get_record('course_modules_completion', ['userid' => $user->id, 'coursemoduleid' => $moduleid]);
        $data = (object)['userid' => $user->id, 'coursemoduleid' => $moduleid, 'completionstate' => $state,
            'viewed' => 0, 'overrideby' => null, 'timemodified' => $at];
        if ($row) { $data->id = $row->id; $DB->update_record('course_modules_completion', $data); }
        else { $DB->insert_record('course_modules_completion', $data); }
    };
    $completion($users['old'], $cmid, 2, $old);
    $completion($users['pass'], $cmid, 2, $inside);
    $completion($users['complete'], $cmid, 1, $inside);
    $completion($users['fail'], $cmid, 3, $inside);
    $completion($users['pageonly'], $pageid, 1, $inside);
    $newcampaign = function($period) use ($DB, $course, $cmid) {
        $c = (object)['courseid' => $course->id, 'name' => 'Fixture ' . $period, 'period' => $period,
            'timezone' => 'America/New_York', 'cmids' => (string)$cmid, 'delaydays' => 7, 'sendhour' => 9,
            'subject' => 'Reminder {month}', 'body' => 'Hi {firstname}: {courselink}', 'enabled' => 1,
            'bodyformat' => FORMAT_PLAIN, 'approvedbranch' => service::platform_branch(), 'logohash' => '',
            'allh5p' => 0, 'trainingurl' => 'https://cyberhealth.senscy.com/course/view.php?id=14', 'logourl' => '',
            'approvedby' => 0, 'approvedat' => time(), 'approvedhash' => '', 'cursorid' => 0, 'cursorslot' => -1,
            'timecreated' => time(), 'timemodified' => time()];
        $c->approvedhash = rules::fingerprint($c);
        $c->id = $DB->insert_record(service::CAMPAIGNS, $c);
        return $c;
    };
    $campaign = $newcampaign('2026-12');
    $config = ['plugin' => 'local_senscyreminders', 'name' => 'enabled'];
    if ($DB->record_exists('config_plugins', $config)) { $DB->set_field('config_plugins', 'value', '1', $config); }
    else { $DB->insert_record('config_plugins', (object)($config + ['value' => '1'])); }
    $reason = fn($key) => service::evaluate($campaign, $users[$key], $now)['reason'];
    $check(service::validate($campaign) === [], 'Valid H5P campaign rejected');
    $debugcm = get_fast_modinfo($course, $users['old']->id)->get_cm($cmid);
    $check($reason('old') === 'remind', 'Prior-month completion must not count: ' . $reason('old') . ' ' . json_encode([
        'visible' => $debugcm->visible, 'available' => $debugcm->available, 'info' => $debugcm->availableinfo,
        'json' => $debugcm->availability, 'groups' => groups_get_user_groups($course->id, $users['old']->id, true),
        'section' => get_fast_modinfo($course, $users['old']->id)->get_section_info($debugcm->sectionnum)->available,
        'groupmember' => groups_is_member($groupid, $users['old']->id),
        'canview' => has_capability('mod/h5pactivity:view', context_module::instance($cmid), $users['old']->id)]));
    $check($reason('pass') === 'completed', 'Pass state 2 must suppress');
    $check($reason('complete') === 'completed', 'Complete state 1 must suppress');
    $check($reason('fail') === 'remind', 'Fail state 3 must not suppress');
    $check($reason('pageonly') === 'remind', 'A page/certificate outside the pool must not suppress');
    $check($reason('suspended') === 'ineligible', 'Suspended user must be excluded');
    $check($reason('unenrolled') === 'ineligible', 'Inactive enrolment must be excluded');
    $check($reason('emailstop') === 'email_unavailable', 'Respect email stop preference');
    $check($reason('restricted') === 'no_accessible_training', 'Respect group/section availability');
    $blocked=clone $users['old'];$blocked->auth='nologin';
    $check(service::evaluate($campaign,$blocked,$now)['reason']==='ineligible','Accounts with login disabled must not receive reminders');
    $blocked->auth='manual';$blocked->email='fixture@example.invalid';
    $check(service::evaluate($campaign,$blocked,$now)['reason']==='email_unavailable','Moodle-suppressed invalid-domain addresses must not be reported as submitted');
    $bad = clone $campaign; $bad->cmids = (string)$pageid;
    $check(service::validate($bad) !== [], 'Non-H5P pool must fail closed');
    $DB->set_field('h5pactivity', 'enabletracking', 0, ['id' => $h5pid]);
    $check(service::validate($campaign) !== [], 'Disabled H5P tracking must fail closed');
    $DB->set_field('h5pactivity', 'enabletracking', 1, ['id' => $h5pid]);
    $attemptid = $DB->insert_record('h5pactivity_attempts', (object)['h5pactivityid' => $h5pid,
        'userid' => $users['old']->id, 'timecreated' => $inside, 'timemodified' => $inside, 'attempt' => 1]);
    $result = (object)['attemptid' => $attemptid, 'subcontent' => 'question1', 'timecreated' => $inside,
        'interactiontype' => 'other', 'description' => '', 'correctpattern' => '', 'response' => '',
        'additionals' => '', 'completion' => 1, 'success' => 1, 'maxscore' => 1, 'rawscore' => 1];
    $resultid = $DB->insert_record('h5pactivity_attempts_results', $result);
    $check($reason('old') === 'remind', 'Answering a sub-question must not count as a video completion');
    $DB->set_field('h5pactivity_attempts_results', 'subcontent', '', ['id' => $resultid]);
    $DB->set_field('h5pactivity_attempts_results', 'success', 0, ['id' => $resultid]);
    $check($reason('old') === 'remind', 'A failed fresh root attempt must not count');
    $DB->set_field('h5pactivity_attempts_results', 'success', 1, ['id' => $resultid]);
    $decision = service::evaluate($campaign, $users['old'], $now);
    $check($decision['reason'] === 'completed' && $decision['evidencekind'] === 'h5p_result', 'Successful retake must count');
    $DB->delete_records('h5pactivity_attempts_results', ['id' => $resultid]);
    // Completion occurs after preview but before the scheduled send.
    $check($reason('old') === 'remind', 'Preview fixture must start incomplete for this month');
    $completion($users['old'], $cmid, 2, $inside);
    $calls = [];
    $transport = function($c, $u) use (&$calls) { $calls[] = $u->id; return true; };
    $totals = service::process($campaign->id, $now, $transport);
    $check(count($calls) === 2 && in_array($users['fail']->id, $calls) && in_array($users['pageonly']->id, $calls), 'Only truly outstanding eligible learners should submit: ' . json_encode([$calls,$totals,service::runtime_reason($campaign)]));
    $check(!in_array($users['old']->id, $calls), 'Final completion recheck must suppress after preview');
    service::process($campaign->id, $now, $transport);
    $DB->set_field(service::CAMPAIGNS, 'cursorid', 0, ['id' => $campaign->id]);
    service::process($campaign->id, $now, $transport);
    $check(count($calls) === 2, 'Repeated cron/cursor replay must not duplicate');
    $completion($users['fail'], $cmid, 2, $inside + 86400);
    service::process($campaign->id, $now + 7*86400, $transport);
    $check(count($calls) === 3 && end($calls) === $users['pageonly']->id, 'Next week must suppress newly completed learners');
    // No real transport is allowed while noemailever is set.
    $jan = $newcampaign('2027-01');
    $jannow = (new DateTimeImmutable('2027-01-08 09:00:00', new DateTimeZone('America/New_York')))->getTimestamp();
    service::process($jan->id, $jannow);
    $check(!$DB->record_exists(service::LOG, ['campaignid' => $jan->id]), 'Blocked site email must not reserve/claim submission');
    $jancalls = [];
    service::process($jan->id, $jannow, function($c,$u) use (&$jancalls,$DB,$config) {
        $jancalls[] = $u->id;
        $DB->set_field('config_plugins', 'value', '0', $config);
        return true;
    });
    $check(count($jancalls) === 1, 'Master pause during a batch must stop later recipients');
    $DB->set_field('config_plugins', 'value', '1', $config);
    $DB->set_field(service::CAMPAIGNS, 'body', 'Changed without approval', ['id' => $jan->id]);
    service::process($jan->id, $jannow, $transport);
    $check(count($calls) === 3, 'Changed rules must invalidate approval');
    // Shared addresses: one submission, not one per account.
    $DB->set_field('user', 'email', $users['fail']->email, ['id' => $users['pageonly']->id]);
    $apr = $newcampaign('2027-04');
    $aprnow = (new DateTimeImmutable('2027-04-08 09:00:00', new DateTimeZone('America/New_York')))->getTimestamp();
    foreach (['old','pass','complete'] as $key) { $completion($users[$key], $cmid, 1, $aprnow - 60); }
    $completion($users['fail'], $cmid, 0, $aprnow-60);
    $aprcalls = [];
    service::process($apr->id, $aprnow, function($c,$u) use (&$aprcalls) { $aprcalls[]=$u->email; return true; });
    $check(count($aprcalls) === 1, 'Shared address must only receive one reminder');
    // Transport uncertainty is held, never automatically retried in that week.
    $may = $newcampaign('2027-05');
    $maynow = (new DateTimeImmutable('2027-05-08 09:00:00', new DateTimeZone('America/New_York')))->getTimestamp();
    foreach (['old','pass','complete'] as $key) { $completion($users[$key], $cmid, 1, $maynow - 60); }
    $maycalls=0;
    $failedtransport=function() use (&$maycalls) { $maycalls++; return false; };
    service::process($may->id, $maynow, $failedtransport);
    $DB->set_field(service::CAMPAIGNS, 'cursorid', 0, ['id' => $may->id]);
    service::process($may->id, $maynow, $failedtransport);
    $check($maycalls === 1 && $DB->record_exists(service::LOG, ['campaignid' => $may->id, 'status' => 'held']), 'Uncertain submission must remain held without retry');
    // An interrupted dispatch reservation also prevents resending, including to a shared address.
    $june = $newcampaign('2027-06');
    $junenow = (new DateTimeImmutable('2027-06-08 09:00:00', new DateTimeZone('America/New_York')))->getTimestamp();
    foreach (['old','pass','complete'] as $key) { $completion($users[$key], $cmid, 1, $junenow - 60); }
    $DB->insert_record(service::LOG, (object)['campaignid' => $june->id, 'userid' => $users['fail']->id,
        'slot' => rules::slot($june, $junenow), 'emailhash' => rules::emailhash($users['fail']->email), 'status' => 'reserved',
        'reason' => 'interrupted_fixture', 'timecreated' => $junenow, 'processedat' => $junenow]);
    $before=count($calls);
    service::process($june->id, $junenow, $transport);
    $check(count($calls) === $before, 'Interrupted reservations must not automatically resend');
    $htmlcampaign = clone $campaign;
    $htmlcampaign->bodyformat=FORMAT_HTML;
    $htmlcampaign->body='<p>Hi {firstname} {lastname}</p><p><a href="{courselink}">Click training here</a></p>';
    $person=clone $users['old']; $person->firstname='<script>alert(1)</script>'; $person->lastname='Last & Name';
    [$sub,$plain,$html]=\local_senscyreminders\message::render($htmlcampaign,$person);
    $check(strpos($html,'<script>')===false && strpos($html,'&lt;script&gt;')!==false, 'HTML names must be escaped');
    $check(strpos($html,'href="https://cyberhealth.senscy.com/course/view.php?id=14"')!==false, 'Training hyperlink uses configured URL: '.$html);
    $check(strpos($plain,'https://cyberhealth.senscy.com/course/view.php?id=14')!==false, 'Plain text preserves training link');
    $check(strpos(\local_senscyreminders\message::clean_template($htmlcampaign->body), '{courselink}')!==false, 'Editor sanitization preserves training-link placeholder');
    $branch=service::platform_branch();
    $DB->set_field('config','value','99.0 test',['name'=>'release']);
    $check(service::runtime_reason($campaign)==='platform_changed', 'Core branch upgrade blocks unreviewed run');
    $before=count($calls); service::process($june->id,$junenow,$transport);
    $check(count($calls)===$before, 'Upgrade hold does not submit email');
    $DB->set_field('config','value',$CFG->release,['name'=>'release']);
    $check(service::runtime_reason($campaign)==='', 'Reviewed original branch remains valid');
    $auto=clone $campaign; $auto->allh5p=1; $auto->cmids='';
    $check(service::activity_ids($auto)===[$cmid], 'Automatic pool includes tracked H5P only');
    $auto->cmids=(string)$cmid;
    [$newcmid]=$makeactivity('h5pactivity','New training');
    rebuild_course_cache($course->id,true);
    $check(in_array($newcmid,service::activity_ids($auto),true), 'Automatically include a newly added tracked H5P');
    $DB->set_field('h5pactivity','enabletracking',0,['id'=>$h5pid]);
    $check(service::validate($auto)!==[], 'Shrinking an approved pool requires review even when other H5Ps remain');
    $check(service::evaluate($auto,$users['old'],$now)['reason']==='invalid_activities', 'Pool shrink fails closed immediately before submission');
    $DB->set_field('h5pactivity','enabletracking',1,['id'=>$h5pid]);
    $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j0d0AAAAASUVORK5CYII=');
    $logo=get_file_storage()->create_file_from_string((object)['contextid'=>context_system::instance()->id,
        'component'=>'local_senscyreminders','filearea'=>'logo','itemid'=>$campaign->id,'filepath'=>'/',
        'filename'=>'fixture-logo.png','mimetype'=>'image/png'], $png);
    $check(\local_senscyreminders\assets::valid($logo), 'Uploaded PNG logo passes image checks');
    $check(service::runtime_reason($campaign)==='logo_changed', 'Unreviewed logo change blocks sending');
    $htmlcampaign->logohash=\local_senscyreminders\assets::hash($campaign->id);
    [$sub,$plain,$html]=\local_senscyreminders\message::render($htmlcampaign,$users['old']);
    $check(strpos($html,'fixture-logo.png')!==false && strpos($html,'<img')!==false, 'Uploaded logo renders above email message');
    $logo->delete();
    service::stop_all();
    $check(!service::global_enabled() && !$DB->record_exists(service::CAMPAIGNS,['enabled'=>1]), 'Stop All disables master and every run');
    service::activate($campaign->id, get_admin()->id);
    $check(service::global_enabled() && $DB->count_records(service::CAMPAIGNS,['enabled'=>1])===1, 'Explicit Start enables only the reviewed run');
    $check($DB->count_records(service::LOG,['campaignid'=>$campaign->id])>0, 'Resume preserves prior send ledger');
    $DB->set_field('course', 'visible', 0, ['id' => $course->id]);
    $check(service::evaluate($campaign, $users['old'], $now)['reason'] === 'course_unavailable', 'Hidden course must suppress');
    $PAGE->set_context(context_system::instance());
    $form = new \local_senscyreminders\form\campaign(null, ['id' => 0, 'course' => $course, 'activities' => service::activities($course->id)]);
    $check($form instanceof moodleform, 'Campaign form must instantiate against Moodle');
    \core\session\manager::set_user(get_admin());
    $form->set_data((object)['firstsendtext'=>'2026-12-08 09:00','name'=>'Test run',
        'body_editor'=>['text'=>'<p>Hi {firstname}</p>','format'=>FORMAT_HTML,'itemid'=>0]]);
    $formhtml=$form->render();
    $check(strpos($formhtml,'firstsendtext')!==false && strpos($formhtml,'body_editor')!==false
        && strpos($formhtml,'trainingurl')!==false, 'Date, HTML editor and training link controls render');
    $DB->set_field('course','visible',1,['id'=>$course->id]);
    if ($DB->get_manager()->table_exists('senscyreminder')) {
        require $CFG->dirroot . '/mod/senscyreminder/tests/activity_checks.php';
    }
} catch (Throwable $e) {
    $failure = $e;
} finally {
    try { $tx->rollback(new RuntimeException('SCR_FIXTURE_ROLLBACK')); }
    catch (RuntimeException $e) { if ($e->getMessage() !== 'SCR_FIXTURE_ROLLBACK' && !$failure) { $failure=$e; } }
    if ($courseid) { course_modinfo::purge_course_cache($courseid); }
    cache::make('core', 'config')->delete('local_senscyreminders');
}
if ($failure) { throw $failure; }
echo "PASS: $assertions Moodle integration assertions; all fixture rows rolled back; zero real emails.\n";
