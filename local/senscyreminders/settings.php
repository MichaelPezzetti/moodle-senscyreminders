<?php
defined('MOODLE_INTERNAL') || die();
if ($hassiteconfig) {
    $settings = new admin_settingpage('local_senscyreminders', get_string('pluginname', 'local_senscyreminders'));
    $ADMIN->add('localplugins', $settings);
    $settings->add(new admin_setting_configcheckbox('local_senscyreminders/enabled',
        get_string('enabled', 'local_senscyreminders'), get_string('enabled_desc', 'local_senscyreminders'), 0));
    $settings->add(new admin_setting_configtext('local_senscyreminders/batchsize',
        get_string('batchsize', 'local_senscyreminders'), '', 250, PARAM_INT));
    $settings->add(new admin_setting_heading('local_senscyreminders/manage', '',
        html_writer::link(new moodle_url('/local/senscyreminders/index.php'), get_string('manage', 'local_senscyreminders'))));
}
