<?php
/** Included by the engine's rollback integration harness. No real mail transport. */
defined('LOCAL_SCR_TESTING') && defined('CLI_SCRIPT') && CLI_SCRIPT || die();
use local_senscyreminders\service;
require_once $CFG->dirroot.'/course/modlib.php';
require_once $CFG->dirroot.'/mod/senscyreminder/lib.php';
\core\session\manager::set_user(get_admin());
$moduleid=$DB->get_field('modules','id',['name'=>'senscyreminder'],MUST_EXIST);
$check($DB->count_records('task_scheduled',['component'=>'mod_senscyreminder'])===0,'Activity does not create a second email scheduler');
$check($DB->count_records('task_scheduled',['component'=>'local_senscyreminders'])===1,'Only one reminder-engine scheduled task exists');
$info=add_moduleinfo((object)['modulename'=>'senscyreminder','module'=>$moduleid,'name'=>'Training reminders fixture',
    'course'=>$course->id,'section'=>0,'visible'=>0,'completion'=>COMPLETION_TRACKING_NONE],$course);
$activitycm=$DB->get_record('course_modules',['id'=>$info->coursemodule],'*',MUST_EXIST);
rebuild_course_cache($course->id,true);$course=get_course($course->id);
$check($DB->record_exists('senscyreminder',['id'=>$info->instance,'course'=>$course->id]), 'Activity created through the real Moodle module API');
$check(!$DB->record_exists(service::CAMPAIGNS,['courseid'=>$course->id,'enabled'=>1]), 'Adding the activity pauses existing reminders; never starts mail');
$check(strpos(service::manage_url((int)$course->id)->out(false),'/mod/senscyreminder/view.php?id='.$activitycm->id)!==false, 'Reminder controls return to the course activity');
$check(has_capability('mod/senscyreminder:view',context_module::instance($activitycm->id),get_admin()), 'Administrator can open the activity');
$check(!has_capability('mod/senscyreminder:view',context_module::instance($activitycm->id),$users['fail']), 'Learners cannot open recipient/message controls');
try {
    senscyreminder_add_instance((object)['course'=>$course->id,'name'=>'Duplicate']);
    $check(false,'Duplicate activity was allowed');
} catch (moodle_exception $e) { $check($e->errorcode==='duplicateactivity','One activity per course enforced'); }
$july=$newcampaign('2027-07');
$julynow=(new DateTimeImmutable('2027-07-08 09:00:00',new DateTimeZone('America/New_York')))->getTimestamp();
$check(service::runtime_reason($july)==='', 'Hidden administrative activity does not act as an email stop switch');
$julycalls=[];
$julytransport=function($c,$u) use (&$julycalls){$julycalls[]=$u->id;return true;};
foreach (['maintenance_enabled','upgraderunning'] as $flag) {
    $prior=$DB->get_record('config',['name'=>$flag]);
    $flagvalue=$flag==='upgraderunning' ? (string)(time()+600) : '1';
    if ($prior) { $DB->set_field('config','value',$flagvalue,['name'=>$flag]); }
    else { $DB->insert_record('config',(object)['name'=>$flag,'value'=>$flagvalue]); }
    $check(service::runtime_reason($july)==='site_maintenance','Site safety flag holds sending: '.$flag);
    service::process($july->id,$julynow,$julytransport);
    $check(!$julycalls && !$DB->record_exists(service::LOG,['campaignid'=>$july->id]),'No submission or reservation during '.$flag);
    if ($prior) { $DB->set_field('config','value',$prior->value,['name'=>$flag]); }
    else { $DB->delete_records('config',['name'=>$flag]); }
}
service::process($july->id,$julynow,$julytransport);
$check(count($julycalls)>0,'Approved reminders process with the administrative activity hidden from learners');
$DB->set_field('course_modules','deletioninprogress',1,['id'=>$activitycm->id]);
$check(service::runtime_reason($july)==='activity_unavailable','Pending asynchronous activity deletion holds sending');
$before=count($julycalls);service::process($july->id,$julynow+7*86400,$julytransport);
$check(count($julycalls)===$before,'No submission while the activity is being deleted');
$DB->set_field('course_modules','deletioninprogress',0,['id'=>$activitycm->id]);
rebuild_course_cache($course->id,true);$course=get_course($course->id);
require_once $CFG->dirroot.'/mod/senscyreminder/mod_form.php';
$PAGE=new moodle_page();$PAGE->set_course($course);$OUTPUT=new \core\output\bootstrap_renderer();
$current=clone $activitycm;$current->instance=$info->instance;$current->modulename='senscyreminder';$current->name='Training reminders fixture';
$activityform=new mod_senscyreminder_mod_form($current,0,$activitycm,$course);
$check($activityform instanceof moodleform_mod,'Activity settings form instantiates on this Moodle version');
$other=clone $july;unset($other->id);$other->courseid=SITEID;$other->period='2040-12';$other->name='OTHER COURSE PRIVATE RUN';$other->enabled=0;
$DB->insert_record(service::CAMPAIGNS,$other);
$_GET['id']=$activitycm->id;$_SERVER['REQUEST_METHOD']='GET';
$PAGE=new moodle_page();$OUTPUT=new \core\output\bootstrap_renderer();
$oldbuffer=ob_get_level();ob_start();
try { require $CFG->dirroot.'/mod/senscyreminder/view.php'; $viewhtml=ob_get_clean(); }
catch (Throwable $e) { while(ob_get_level()>$oldbuffer){ob_end_clean();} throw $e; }
$check(strpos($viewhtml,'Stop ALL reminders')!==false && strpos($viewhtml,'Prepare run')!==false,'Activity view renders the run and stop controls');
$check(strpos($viewhtml,'OTHER COURSE PRIVATE RUN')===false,'Activity view only exposes its own course runs');
$check(strpos($viewhtml,'/local/senscyreminders/edit.php')!==false,'Activity message link resolves to the correct editor');
$historycount=$DB->count_records(service::LOG,['campaignid'=>$july->id]);
senscyreminder_update_instance((object)['instance'=>$info->instance,'name'=>'Renamed training reminders']);
$check(!$DB->record_exists(service::CAMPAIGNS,['courseid'=>$course->id,'enabled'=>1]),'Updating activity settings pauses its course reminders');
$DB->set_field(service::CAMPAIGNS,'enabled',1,['id'=>$july->id]);
if (method_exists(\core_courseformat\local\cmactions::class,'delete')) {
    \core_courseformat\formatactions::cm($course)->delete($activitycm->id,false);
} else {
    course_delete_module($activitycm->id,false);
}
$check(!$DB->record_exists('course_modules',['id'=>$activitycm->id]),'Activity deletion through Moodle module API succeeds');
$check(!$DB->get_field(service::CAMPAIGNS,'enabled',['id'=>$july->id]),'Deleting the activity pauses its reminders');
$check($DB->count_records(service::LOG,['campaignid'=>$july->id])===$historycount,'Deleting activity retains duplicate-protection and audit history');
$check(!$DB->record_exists('senscyreminder',['id'=>$info->instance]),'Deleted activity configuration removed');
