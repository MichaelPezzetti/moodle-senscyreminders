<?php
namespace local_senscyreminders\form;
defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->libdir . '/formslib.php');

final class campaign extends \moodleform {
    public function definition(): void {
        $m = $this->_form;
        $data = $this->_customdata;
        $m->addElement('hidden', 'id', $data['id']); $m->setType('id', PARAM_INT);
        $m->addElement('hidden', 'courseid', $data['course']->id); $m->setType('courseid', PARAM_INT);
        $m->addElement('static', 'course', get_string('course'), format_string($data['course']->fullname));
        $m->addElement('text', 'name', 'Run name', ['size' => 60]); $m->setType('name', PARAM_TEXT);
        $m->addElement('text', 'timezone', 'Timezone'); $m->setType('timezone', PARAM_TEXT);
        $m->setDefault('timezone', 'America/New_York');
        $m->addElement('text', 'firstsendtext', 'First sending date and time', ['placeholder' => '2026-12-01 09:00']);
        $m->setType('firstsendtext', PARAM_TEXT);
        $m->addElement('static', 'scheduleinfo', '', 'Use YYYY-MM-DD HH:MM in the timezone above. Weekly dates through that month-end appear on the next screen. You can remove dates before clicking Start. Nothing starts automatically.');
        $m->addElement('static', 'ruleinfo', 'Completion rule', 'Any automatically tracked native H5P training in this course completed during the training month stops reminders. Certificates and other activity types do not count.');
        $m->addElement('text', 'trainingurl', 'Training link', ['size' => 80, 'maxlength' => 1024]); $m->setType('trainingurl', PARAM_URL);
        $m->setDefault('trainingurl', 'https://cyberhealth.senscy.com/course/view.php?id=14');
        $m->addElement('text', 'subject', 'Email subject', ['size' => 70, 'maxlength' => 255]); $m->setType('subject', PARAM_TEXT);
        $m->setDefault('subject', 'Reminder: your cybersecurity training');
        $m->addElement('filemanager', 'logo', 'Logo at top of email', null, \local_senscyreminders\assets::options());
        $m->addElement('static', 'logoinfo', '', 'PNG, JPEG or GIF, up to 512 KB. This branding image is publicly viewable so email clients can display it without a Moodle login.');
        $m->addElement('text', 'logourl', 'Or use a public logo image URL', ['size' => 80, 'maxlength' => 1024]); $m->setType('logourl', PARAM_URL);
        $m->setDefault('logourl', 'https://gophish-images.s3.us-east-2.amazonaws.com/SensCy-Logo-Horiz-wTag-600+(2).png');
        $m->addElement('editor', 'body_editor', 'Email message', ['rows' => 14], [
            'context' => \context_system::instance(), 'maxfiles' => 0, 'maxbytes' => 524288,
            'trusttext' => false, 'noclean' => false, 'subdirs' => false]);
        $m->setType('body_editor', PARAM_RAW);
        $m->addElement('static', 'tokens', '', 'Use {firstname} and {lastname} for each employee. Link text such as “Click training here” can point to {courselink}, or to your own URL. Also available: {coursename}, {month}, %userfirstname%, %userlastname%. Upload the logo above; other images in the message must use public URLs.');
        $this->add_action_buttons(true, 'Save message and review sending dates');
    }
    public function validation($data, $files): array {
        global $DB, $USER;
        $errors = parent::validation($data, $files);
        if (trim($data['name']) === '' || strlen($data['name']) > 255) { $errors['name'] = 'Enter a name of up to 255 characters.'; }
        if (strlen($data['subject']) > 255) { $errors['subject'] = 'Maximum 255 characters.'; }
        try {
            $c = (object)$data;
            $c->courseid = $this->_customdata['course']->id;
            $c->firstsend = \local_senscyreminders\rules::parse_start($data['firstsendtext'], $data['timezone']);
            $date = (new \DateTimeImmutable('@' . $c->firstsend))->setTimezone(new \DateTimeZone($c->timezone));
            $c->period = $date->format('Y-m');
            $c->senddates = json_encode(\local_senscyreminders\rules::generate($c->firstsend, $c->timezone));
            $c->allh5p = 1; $c->cmids = '';
            $c->body = $data['body_editor']['text'] ?? ''; $c->bodyformat = FORMAT_HTML;
            foreach (\local_senscyreminders\service::validate($c) as $error) { $errors['body_editor'] = ($errors['body_editor'] ?? '') . ' ' . $error; }
            $other = $DB->get_record('local_scr_campaigns', ['courseid' => $c->courseid, 'period' => $c->period]);
            if ($other && (int)$other->id !== (int)$this->_customdata['id']) { $errors['firstsendtext'] = 'A run already exists for this course and training month. Edit that run.'; }
        } catch (\Exception $e) { $errors['firstsendtext'] = 'Use a valid YYYY-MM-DD HH:MM time and timezone.'; }
        if (!empty($data['logo'])) {
            foreach (get_file_storage()->get_area_files(\context_user::instance($USER->id)->id, 'user', 'draft', $data['logo'], 'id', false) as $file) {
                if (!\local_senscyreminders\assets::valid($file)) { $errors['logo'] = 'Upload a PNG, JPEG or GIF under 512 KB and 4000 pixels per dimension.'; }
            }
        }
        return $errors;
    }
}
