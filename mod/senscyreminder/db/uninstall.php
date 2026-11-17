<?php
defined('MOODLE_INTERNAL') || die();
function xmldb_senscyreminder_uninstall(): bool {
    \local_senscyreminders\service::stop_all();
    return true;
}
