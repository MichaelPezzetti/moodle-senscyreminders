<?php
defined('MOODLE_INTERNAL') || die();
$tasks = [[
    'classname' => '\local_senscyreminders\task\send_reminders',
    'blocking' => 0, 'minute' => '*', 'hour' => '*', 'day' => '*', 'month' => '*', 'dayofweek' => '*',
]];
