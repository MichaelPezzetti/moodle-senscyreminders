<?php
require_once(__DIR__ . '/../../config.php');
require_login();
$context = context_system::instance();
require_capability('local/senscyreminders:manage', $context);
$id = required_param('id', PARAM_INT);
$page = max(0, optional_param('page', 0, PARAM_INT));
$campaign = $DB->get_record('local_scr_campaigns', ['id' => $id], '*', MUST_EXIST);
$PAGE->set_context($context);
$PAGE->set_url('/local/senscyreminders/history.php', ['id' => $id]);
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Reminder history');
$PAGE->set_heading('Reminder history: ' . $campaign->name);
echo $OUTPUT->header();
echo $OUTPUT->notification(get_string('historynote', 'local_senscyreminders'), 'info');
echo $OUTPUT->notification('A dev_diversion_accepted entry is a test submission to the configured test mailbox, not a delivery to the learner. submitted still means mail transport acceptance, not confirmed inbox delivery.', 'info');
echo html_writer::link(\local_senscyreminders\service::manage_url((int)$campaign->courseid), 'Reminder controls');
$table = new html_table();
$table->head = ['User', 'Sending date', 'Status', 'Reason', 'Processed'];
$sql = 'SELECT l.*,u.firstname,u.lastname FROM {local_scr_log} l LEFT JOIN {user} u ON u.id=l.userid WHERE campaignid=:id ORDER BY l.id DESC';
foreach ($DB->get_records_sql($sql, ['id' => $id], $page * 100, 100) as $row) {
    $table->data[] = [s(trim(($row->firstname ?? '') . ' ' . ($row->lastname ?? ''))), s(userdate($row->slot, '%Y-%m-%d %H:%M', $campaign->timezone)),
        s($row->status), s($row->reason), userdate($row->processedat, '', $campaign->timezone)];
}
echo html_writer::table($table);
echo $OUTPUT->paging_bar($DB->count_records('local_scr_log', ['campaignid' => $id]), $page, 100, $PAGE->url);
echo $OUTPUT->footer();
