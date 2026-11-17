<?php
defined('MOODLE_INTERNAL') || die();

/** Put the administrative reminder controls in the existing course navigation. */
function local_senscyreminders_extend_navigation_course($navigation, $course, $context): void {
    if (has_capability('local/senscyreminders:manage', context_system::instance())) {
        $navigation->add('Training reminders', \local_senscyreminders\service::manage_url((int)$course->id),
            navigation_node::TYPE_CUSTOM, null, 'senscyreminders');
    }
}

/** Branding-only public images allow email clients to display the logo without a Moodle login. */
function local_senscyreminders_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []): bool {
    if ($context->contextlevel !== CONTEXT_SYSTEM || $filearea !== 'logo' || count($args) !== 2) {
        return false;
    }
    $id = (int)$args[0];
    $filename = clean_param($args[1], PARAM_FILE);
    $file = get_file_storage()->get_file($context->id, 'local_senscyreminders', 'logo', $id, '/', $filename);
    if (!$file || $file->is_directory() || !\local_senscyreminders\assets::valid($file)) {
        return false;
    }
    send_stored_file($file, DAYSECS, 0, false, $options);
    return true;
}
