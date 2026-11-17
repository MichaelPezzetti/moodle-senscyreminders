<?php
// SPDX-License-Identifier: GPL-3.0-or-later
defined('MOODLE_INTERNAL') || die();
$plugin->component = 'local_senscyreminders';
$plugin->version = 2026111700;
$plugin->requires = 2024100700;
$plugin->supported = [405, 502];
$plugin->maturity = MATURITY_BETA;
$plugin->release = '0.3.1';
$plugin->dependencies = ['mod_h5pactivity' => 2024100700];
