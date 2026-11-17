<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');
require_login();
$context = context_system::instance();
require_capability('local/senscyreminders:manage', $context);
use local_senscyreminders\service;
use local_senscyreminders\rules;
use local_senscyreminders\assets;
$id = optional_param('id', 0, PARAM_INT);
$templateid = optional_param('templateid', 0, PARAM_INT);
$campaign = $id ? $DB->get_record(service::CAMPAIGNS, ['id' => $id], '*', MUST_EXIST) : null;
$template = !$campaign && $templateid ? $DB->get_record(service::CAMPAIGNS, ['id' => $templateid], '*', MUST_EXIST) : null;
$courseid = $campaign ? (int)$campaign->courseid : ($template ? (int)$template->courseid : required_param('courseid', PARAM_INT));
$course = get_course($courseid);
$PAGE->set_context($context);
$PAGE->set_url('/local/senscyreminders/edit.php', ['id' => $id, 'courseid' => $courseid, 'templateid' => $templateid]);
$PAGE->set_pagelayout('admin'); $PAGE->set_title('Prepare training reminder run'); $PAGE->set_heading('Prepare training reminder run');
$form = new \local_senscyreminders\form\campaign(null, ['id' => $id, 'course' => $course]);
if ($form->is_cancelled()) { redirect(service::manage_url($courseid)); }
if ($data = $form->get_data()) {
    $lock = service::lock();
    if (!$lock) { throw new moodle_exception('busy', 'local_senscyreminders'); }
    try {
        $row = $campaign ? clone $campaign : (object)['timecreated' => time(), 'delaydays' => 0, 'sendhour' => 0];
        $first = rules::parse_start($data->firstsendtext, $data->timezone);
        $row->firstsend = $first; $row->timezone = $data->timezone;
        $row->period = (new DateTimeImmutable('@' . $first))->setTimezone(new DateTimeZone($row->timezone))->format('Y-m');
        if (!$campaign || (int)$campaign->firstsend !== $first || $campaign->timezone !== $row->timezone) {
            $row->senddates = json_encode(rules::generate($first, $row->timezone));
        }
        $row->courseid = $courseid; $row->name = $data->name; $row->subject = $data->subject;
        $row->body = \local_senscyreminders\message::clean_template($data->body_editor['text']); $row->bodyformat = FORMAT_HTML;
        $row->trainingurl = $data->trainingurl; $row->logourl = $data->logourl; $row->allh5p = 1;
        $row->cmids = implode(',', array_keys(service::activities($courseid)));
        $row->enabled = 0; $row->approvedhash = ''; $row->approvedby = 0; $row->approvedat = 0; $row->approvedbranch = '';
        $row->cursorid = 0; $row->cursorslot = -1; $row->timemodified = time();
        if ($errors = service::validate($row)) { throw new moodle_exception('invalidcampaign', 'local_senscyreminders', '', implode(' ', $errors)); }
        if ($id) { $DB->update_record(service::CAMPAIGNS, $row); }
        else { $id = $DB->insert_record(service::CAMPAIGNS, $row); }
        file_save_draft_area_files($data->logo, $context->id, 'local_senscyreminders', 'logo', $id, assets::options());
        if (($logo = assets::logo($id)) && !assets::valid($logo)) { throw new moodle_exception('invalidlogo', 'local_senscyreminders'); }
        $DB->set_field(service::CAMPAIGNS, 'logohash', assets::hash($id), ['id' => $id]);
    } finally { $lock->release(); }
    redirect(new moodle_url('/local/senscyreminders/preview.php', ['id' => $id]));
}
$defaults = $campaign ? clone $campaign : ($template ? clone $template : new stdClass());
$defaults->id = $id; $defaults->courseid = $courseid;
$defaults->timezone = $defaults->timezone ?? 'America/New_York';
if (!$campaign) {
    $date = $template ? new DateTimeImmutable($template->period . '-01', new DateTimeZone($defaults->timezone)) : new DateTimeImmutable('first day of this month', new DateTimeZone($defaults->timezone));
    if ($template || (int)$date->format('n') % 2 || $date < new DateTimeImmutable('today', new DateTimeZone($defaults->timezone))) { $date = $date->modify('first day of next month'); }
    while ((int)$date->format('n') % 2) { $date = $date->modify('first day of next month'); }
    $defaults->firstsend = $date->setTime(9, 0)->getTimestamp();
    $defaults->name = $date->format('F Y') . ' training reminders';
}
$defaults->firstsendtext = rules::format_start((int)$defaults->firstsend, $defaults->timezone);
$defaults->subject = $defaults->subject ?? 'Reminder: your cybersecurity training';
$defaults->trainingurl = $defaults->trainingurl ?? 'https://cyberhealth.senscy.com/course/view.php?id=14';
$defaults->logourl = $defaults->logourl ?? 'https://gophish-images.s3.us-east-2.amazonaws.com/SensCy-Logo-Horiz-wTag-600+(2).png';
$body = $defaults->body ?? '<p>Hi {firstname} {lastname},</p><p>Please complete your cybersecurity training this month.</p><p><a href="{courselink}">Click training here</a></p><p>Thank you,<br>SensCy</p>';
if (isset($defaults->bodyformat) && (int)$defaults->bodyformat !== (int)FORMAT_HTML) { $body = nl2br(s($body)); }
$defaults->body_editor = ['text' => $body, 'format' => FORMAT_HTML, 'itemid' => 0];
$draftid = file_get_submitted_draft_itemid('logo');
file_prepare_draft_area($draftid, $context->id, 'local_senscyreminders', 'logo', $campaign->id ?? $template->id ?? 0, assets::options());
$defaults->logo = $draftid;
$form->set_data($defaults);
echo $OUTPUT->header();
echo $OUTPUT->notification('Saving edits pauses this run until you review the dates and click Start / Resume. Your logo and training link appear in the email preview.', 'info');
$form->display(); echo $OUTPUT->footer();
