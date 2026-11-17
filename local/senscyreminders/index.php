<?php
// SPDX-License-Identifier: GPL-3.0-or-later
require_once(__DIR__ . '/../../config.php');
require_login();
$context = context_system::instance();
require_capability('local/senscyreminders:manage', $context);
$activitycourse = defined('LOCAL_SCR_ACTIVITY_COURSE') ? LOCAL_SCR_ACTIVITY_COURSE : 0;
if (!$activitycourse) {
$PAGE->set_context($context);
$PAGE->set_url('/local/senscyreminders/index.php');
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('pluginname', 'local_senscyreminders'));
$PAGE->set_heading(get_string('pluginname', 'local_senscyreminders'));
}
use local_senscyreminders\service;
use local_senscyreminders\rules;

$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);
$selectedcourse = $activitycourse ?: optional_param('courseid', 14, PARAM_INT);
if ($action && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    if ($action === 'stopall') {
        service::stop_all();
    } else if ($action === 'pause') {
        // Atomic pause does not wait for a dispatch batch's lock. An in-flight SMTP call cannot be recalled.
        $DB->set_field(service::CAMPAIGNS, 'enabled', 0, ['id' => $id]);
    } else if ($action === 'approve') {
        if (!required_param('reviewed', PARAM_BOOL)) {
            throw new moodle_exception('reviewrequired', 'local_senscyreminders');
        }
        service::activate($id, (int)$USER->id);
    } else if ($action === 'dates') {
        $DB->set_field(service::CAMPAIGNS, 'enabled', 0, ['id' => $id]);
        $lock = service::lock();
        if (!$lock) {
            throw new moodle_exception('busy', 'local_senscyreminders');
        }
        try {
            $campaign = $DB->get_record(service::CAMPAIGNS, ['id' => $id], '*', MUST_EXIST);
            $selected = optional_param_array('dates', [], PARAM_INT);
            $dates = rules::schedule($campaign);
            foreach ($dates as &$date) {
                $dayend = (new DateTimeImmutable('@' . $date['time']))->setTimezone(new DateTimeZone($campaign->timezone))->modify('tomorrow')->setTime(0, 0)->getTimestamp();
                if ($dayend > time()) { $date['enabled'] = in_array($date['time'], $selected); }
            }
            unset($date);
            $campaign->senddates = json_encode($dates);
            $campaign->approvedhash = ''; $campaign->approvedbranch = '';
            $campaign->approvedby = 0; $campaign->approvedat = 0; $campaign->enabled = 0;
            $campaign->cursorid = 0;
            $campaign->cursorslot = -1;
            $DB->update_record(service::CAMPAIGNS, $campaign);
        } finally {
            $lock->release();
        }
    }
    $returncourse = $id ? (int)$DB->get_field(service::CAMPAIGNS, 'courseid', ['id' => $id], MUST_EXIST) : $selectedcourse;
    redirect($action === 'dates' ? new moodle_url('/local/senscyreminders/preview.php', ['id' => $id]) : service::manage_url($returncourse));
}
echo $OUTPUT->header();
echo '<form method="post" action="' . (new moodle_url('/local/senscyreminders/index.php'))->out() . '" class="mb-3"><input type="hidden" name="courseid" value="' . (int)$selectedcourse . '"><input type="hidden" name="sesskey" value="' . sesskey() . '"><button class="btn btn-danger" name="action" value="stopall">Stop ALL reminders</button></form>';
echo html_writer::tag('p', 'Stop All blocks later submissions immediately and pauses every run. A message already handed to the mail server cannot be recalled.');
echo $OUTPUT->notification(get_string('introduction', 'local_senscyreminders'), 'info');
echo $OUTPUT->notification(service::global_enabled() ? get_string('liveon', 'local_senscyreminders') : get_string('liveoff', 'local_senscyreminders'), service::global_enabled() ? 'warning' : 'info');
if (!empty($CFG->noemailever) || !empty($CFG->divertallemailsto) || !empty($CFG->divertallemailsexcept)) {
    echo $OUTPUT->notification(get_string('siteemailblocked', 'local_senscyreminders'), 'info');
}
echo html_writer::link(new moodle_url('/admin/settings.php', ['section' => 'local_senscyreminders']), get_string('settings'));
$table = new html_table();
$table->head = ['Reminder run', 'Course / month', 'Status', 'Actions'];
$runfilter = $activitycourse ? ['courseid' => $activitycourse] : null;
foreach ($DB->get_records(service::CAMPAIGNS, $runfilter, 'period DESC,id DESC') as $campaign) {
    $course = $DB->get_record('course', ['id' => $campaign->courseid]) ?: (object)['fullname' => 'Course removed'];
    [, $monthend] = rules::window($campaign->period, $campaign->timezone);
    $holds = ['platform_changed' => 'Review and resume after Moodle upgrade', 'site_maintenance' => 'Held during site maintenance',
        'logo_changed' => 'Logo changed; review and resume', 'activity_unavailable' => 'Activity being deleted; sending held'];
    $hold = service::runtime_reason($campaign);
    $status = $monthend->getTimestamp() <= time() ? 'Ended' : (!$campaign->enabled ? 'Paused / draft' :
        ($holds[$hold] ?? 'Running on checked dates until month-end'));
    $base = '/local/senscyreminders/';
    $actions = html_writer::link(new moodle_url($base . 'edit.php', ['id' => $campaign->id]), 'Edit') . ' | '
        . html_writer::link(new moodle_url($base . 'preview.php', ['id' => $campaign->id]), 'Dates / preview / start') . ' | '
        . html_writer::link(new moodle_url($base . 'history.php', ['id' => $campaign->id]), 'History');
    if ($campaign->enabled) {
        $actions .= '<form method="post" action="' . (new moodle_url('/local/senscyreminders/index.php'))->out() . '" class="mt-2"><input type="hidden" name="sesskey" value="' . sesskey() . '">'
            . '<input type="hidden" name="id" value="' . (int)$campaign->id . '"><button class="btn btn-secondary" name="action" value="pause">Pause</button></form>';
    }
    $table->data[] = [s($campaign->name), format_string($course->fullname) . ' / ' . s($campaign->period),
        s($status), $actions];
}
echo html_writer::table($table);
$last = $DB->get_records(service::CAMPAIGNS, $runfilter, 'id DESC', '*', 0, 1);
if ($last) {
    echo html_writer::link(new moodle_url('/local/senscyreminders/edit.php', ['templateid' => reset($last)->id]), 'Prepare next training run using previous message', ['class' => 'btn btn-secondary']);
}
echo $OUTPUT->heading('Prepare a training reminder run', 3);
echo '<form method="get" action="' . (new moodle_url('/local/senscyreminders/edit.php'))->out() . '">';
if ($activitycourse) {
    echo '<input type="hidden" name="courseid" value="' . (int)$activitycourse . '">';
} else {
echo '<label for="courseid">Course </label><select id="courseid" name="courseid">';
foreach ($DB->get_records_select('course', 'id<>:site AND enablecompletion=1', ['site' => SITEID], 'fullname', 'id,fullname') as $course) {
    echo '<option value="' . (int)$course->id . '"' . ((int)$course->id === $selectedcourse ? ' selected' : '') . '>' . s($course->fullname) . '</option>';
}
echo '</select>';
}
echo ' <button class="btn btn-primary" type="submit">Prepare run</button></form>';
echo $OUTPUT->footer();
