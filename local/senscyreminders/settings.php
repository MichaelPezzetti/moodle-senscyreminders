<?php
defined('MOODLE_INTERNAL') || die();
if ($hassiteconfig) {
    $settings = new admin_settingpage('local_senscyreminders', get_string('pluginname', 'local_senscyreminders'));
    $ADMIN->add('localplugins', $settings);
    $settings->add(new admin_setting_configcheckbox('local_senscyreminders/enabled',
        get_string('enabled', 'local_senscyreminders'), get_string('enabled_desc', 'local_senscyreminders'), 0));
    $settings->add(new admin_setting_configtext('local_senscyreminders/batchsize',
        get_string('batchsize', 'local_senscyreminders'), '', 250, PARAM_INT));
    $settings->add(new admin_setting_heading('local_senscyreminders/devheading','Bounded DEV diversion testing',
        'Keep Moodle diversion ON, with no exceptions. These settings allow only the selected course and up to 10 learner IDs, with at most 10 transport attempts per run. Settings changes require reviewing/resuming the run. Leave all fields empty on production. Disabling this test mode stops sending; it does not switch an approved test run to normal delivery.'));
    $settings->add(new admin_setting_configcheckbox('local_senscyreminders/devtestenabled','Enable bounded DEV diversion testing','Requires an exact site/mailbox match, no diversion exceptions, and an expiry within 48 hours.',0));
    $settings->add(new admin_setting_configtext('local_senscyreminders/devtesturl','Exact DEV site root URL','Copy the site root, not the activity/course URL. Example: https://dev.example.test','',PARAM_URL));
    $settings->add(new admin_setting_configtext('local_senscyreminders/devtestemail','Required single diversion mailbox','Must exactly equal Moodle’s divertallemailsto address. Test messages are also explicitly addressed to this mailbox.','',PARAM_EMAIL));
    $settings->add(new admin_setting_configtext('local_senscyreminders/devtestcourse','Only this DEV course ID','One numeric course ID. No other course may send while test settings are configured.','',PARAM_RAW_TRIMMED));
    $settings->add(new admin_setting_configtext('local_senscyreminders/devtestusers','Allowed DEV learner IDs','Comma-separated positive Moodle user IDs. Maximum 10. The recipient preview and sending are restricted to this list.','',PARAM_RAW_TRIMMED));
    $settings->add(new admin_setting_configtext('local_senscyreminders/devtestexpires','DEV test expiry — America/New_York','YYYY-MM-DD HH:MM, within the next 48 hours. Sending stops automatically at this time.','',PARAM_RAW_TRIMMED));
    $settings->add(new admin_setting_heading('local_senscyreminders/manage', '',
        html_writer::link(new moodle_url('/local/senscyreminders/index.php'), get_string('manage', 'local_senscyreminders'))));
}
