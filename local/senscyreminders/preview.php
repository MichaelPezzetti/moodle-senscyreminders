<?php
require_once(__DIR__ . '/../../config.php');
require_login();
$context = context_system::instance();
require_capability('local/senscyreminders:manage', $context);
$id = required_param('id', PARAM_INT);
$campaign = $DB->get_record('local_scr_campaigns', ['id' => $id], '*', MUST_EXIST);
$page = max(0, optional_param('page', 0, PARAM_INT));
$download = optional_param('download', 0, PARAM_BOOL);
$PAGE->set_context($context);
$PAGE->set_url('/local/senscyreminders/preview.php', ['id' => $id]);
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Reminder recipient preview');
$PAGE->set_heading('Reminder recipient preview');
use local_senscyreminders\service;
$invalid = service::validate($campaign);
if ($invalid) {
    throw new moodle_exception('invalidcampaign', 'local_senscyreminders', '', implode(' ', $invalid));
}
[$sql, $params] = service::candidate_sql($campaign);
$headings = ['User ID', 'First name', 'Last name', 'Email', 'Decision', 'Completion activity ID', 'Evidence source', 'Completion timestamp (campaign timezone)'];
$rowfor = function($user) use ($campaign) {
    $decision = service::evaluate($campaign, $user, time());
    return [$user->id, $user->firstname, $user->lastname, $user->email,
        get_string('reason_' . $decision['reason'], 'local_senscyreminders'), $decision['cmid'] ?? '', $decision['evidencekind'] ?? '',
        isset($decision['time']) ? userdate($decision['time'], '%Y-%m-%d %H:%M:%S', $campaign->timezone) : ''];
};
if ($download) {
    \core_php_time_limit::raise(0);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="senscy-reminder-preview-' . (int)$id . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    // Prefix formula-like values to avoid spreadsheet formula execution in exported personal data.
    $safe = fn($value) => preg_match('/^[=+@\-\t\r]/', (string)$value) ? "'" . $value : $value;
    fputcsv($out, $headings);
    $users = $DB->get_recordset_sql($sql . ' ORDER BY u.id', $params);
    try {
        foreach ($users as $user) {
            fputcsv($out, array_map($safe, $rowfor($user)));
        }
    } finally {
        $users->close();
        fclose($out);
    }
    exit;
}
echo $OUTPUT->header();if ($deliverynotice=\local_senscyreminders\delivery_policy::describe()) {    echo $OUTPUT->notification(s($deliverynotice),'warning');}
echo $OUTPUT->notification(get_string('previewnote', 'local_senscyreminders'), 'info');
echo $OUTPUT->heading(s($campaign->name) . ' / ' . s($campaign->period), 3);
echo html_writer::link(service::manage_url((int)$campaign->courseid), 'Reminder controls') . ' | '
    . html_writer::link(new moodle_url('/local/senscyreminders/preview.php', ['id' => $id, 'download' => 1]), 'Download full recipient preview CSV');
echo $OUTPUT->heading('Sending dates — ' . s($campaign->timezone), 3);
echo html_writer::tag('p', 'Emails send only on checked dates, after the listed time and before local midnight. Missed days are skipped. Saving dates pauses the run; click Start / Resume after reviewing.');
echo '<form method="post" action="index.php"><input type="hidden" name="sesskey" value="' . sesskey() . '"><input type="hidden" name="id" value="' . (int)$id . '">';
foreach (\local_senscyreminders\rules::schedule($campaign) as $date) {
    $dayend = (new DateTimeImmutable('@' . $date['time']))->setTimezone(new DateTimeZone($campaign->timezone))->modify('tomorrow')->setTime(0, 0)->getTimestamp();
    $past = $dayend <= time();
    echo '<label class="d-block"><input type="checkbox" name="dates[]" value="' . $date['time'] . '"' . ($date['enabled'] ? ' checked' : '') . ($past ? ' disabled' : '') . '> '
        . s(userdate($date['time'], '%A, %B %d, %Y %H:%M', $campaign->timezone)) . ($past ? ' (past)' : '') . '</label>';
}
echo '<button class="btn btn-secondary mt-2" name="action" value="dates">Save selected sending dates</button></form>';
echo html_writer::link(new moodle_url('/local/senscyreminders/edit.php', ['id' => $id]), 'Edit message, logo or first sending date');
$count = $DB->count_records_sql('SELECT COUNT(*) FROM (' . $sql . ') scrusers', $params);
$users = $DB->get_records_sql($sql . ' ORDER BY u.id', $params, $page * 50, 50);
$table = new html_table();
$table->head = $headings;
foreach ($users as $user) {
    $table->data[] = array_map('s', $rowfor($user));
}
echo html_writer::table($table);
echo $OUTPUT->paging_bar($count, $page, 50, $PAGE->url);
[$subject, $body, $html] = \local_senscyreminders\message::render($campaign, $USER);
echo $OUTPUT->heading('Email example (your name)', 3);
echo html_writer::tag('strong', s($subject));
echo html_writer::div($html, 'card card-body my-3');
echo '<form method="post" action="index.php" class="mt-3"><input type="hidden" name="sesskey" value="' . sesskey() . '">'
    . '<input type="hidden" name="id" value="' . (int)$id . '"><label><input type="checkbox" name="reviewed" value="1" required> '
    . s(get_string('reviewconfirmation', 'local_senscyreminders')) . '</label><br>'
    . '<button class="btn btn-primary" name="action" value="approve">Start / Resume reviewed run</button></form>';
echo $OUTPUT->footer();
