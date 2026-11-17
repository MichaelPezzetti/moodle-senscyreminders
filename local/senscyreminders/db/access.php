<?php
defined('MOODLE_INTERNAL') || die();
$capabilities = [
    'local/senscyreminders:manage' => [
        'riskbitmask' => RISK_CONFIG | RISK_PERSONAL | RISK_SPAM,
        'captype' => 'write', 'contextlevel' => CONTEXT_SYSTEM, 'archetypes' => [],
    ],
];
