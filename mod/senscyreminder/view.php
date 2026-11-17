<?php
require_once(__DIR__.'/../../config.php');
$id=required_param('id',PARAM_INT);
[$course,$cm]=get_course_and_cm_from_cmid($id,'senscyreminder');
require_login($course,false,$cm);
require_capability('mod/senscyreminder:view',context_module::instance($cm->id));
require_capability('local/senscyreminders:manage',context_system::instance());
$instance=$DB->get_record('senscyreminder',['id'=>$cm->instance],'*',MUST_EXIST);
$PAGE->set_context(context_module::instance($cm->id));
$PAGE->set_cm($cm,$course); $PAGE->set_url('/mod/senscyreminder/view.php',['id'=>$id]);
$PAGE->set_pagelayout('incourse'); $PAGE->set_title($instance->name); $PAGE->set_heading($course->fullname);
define('LOCAL_SCR_ACTIVITY_COURSE',(int)$course->id);
require($CFG->dirroot.'/local/senscyreminders/index.php');
