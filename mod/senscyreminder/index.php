<?php
require_once(__DIR__.'/../../config.php');
$id=required_param('id',PARAM_INT); $course=get_course($id);
require_login($course);
require_capability('local/senscyreminders:manage',context_system::instance());
redirect(\local_senscyreminders\service::manage_url($id));
