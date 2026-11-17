<?php
defined('MOODLE_INTERNAL') || die();
$capabilities=[
 'mod/senscyreminder:addinstance'=>['riskbitmask'=>RISK_CONFIG|RISK_SPAM,'captype'=>'write','contextlevel'=>CONTEXT_COURSE,'archetypes'=>[]],
 'mod/senscyreminder:view'=>['riskbitmask'=>RISK_PERSONAL,'captype'=>'read','contextlevel'=>CONTEXT_MODULE,'archetypes'=>[]],
];
