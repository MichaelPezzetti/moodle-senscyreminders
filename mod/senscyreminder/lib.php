<?php
defined('MOODLE_INTERNAL') || die();
function senscyreminder_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_ARCHETYPE: return MOD_ARCHETYPE_RESOURCE;
        case FEATURE_MOD_PURPOSE: return MOD_PURPOSE_ADMINISTRATION;
        case FEATURE_MOD_INTRO: case FEATURE_GROUPS: case FEATURE_GROUPINGS:
        case FEATURE_COMPLETION_TRACKS_VIEWS: case FEATURE_COMPLETION_HAS_RULES:
        case FEATURE_GRADE_HAS_GRADE: case FEATURE_GRADE_OUTCOMES:
        case FEATURE_BACKUP_MOODLE2: return false;
        default: return null;
    }
}
function senscyreminder_add_instance($data, $mform=null) {
    global $DB;
    require_capability('local/senscyreminders:manage', context_system::instance());
    if ($DB->record_exists('senscyreminder',['course'=>$data->course])) {
        throw new moodle_exception('duplicateactivity','mod_senscyreminder');
    }
    $data->timecreated=time(); $data->timemodified=time();
    $id=$DB->insert_record('senscyreminder',$data);
    \local_senscyreminders\service::pause_course((int)$data->course);
    return $id;
}
function senscyreminder_update_instance($data, $mform=null) {
    global $DB;
    require_capability('local/senscyreminders:manage',context_system::instance());
    $existing=$DB->get_record('senscyreminder',['id'=>$data->instance],'*',MUST_EXIST);
    \local_senscyreminders\service::pause_course((int)$existing->course);
    $DB->update_record('senscyreminder',(object)['id'=>$existing->id,'name'=>$data->name,'timemodified'=>time()]);
    return true;
}
function senscyreminder_delete_instance($id) {
    global $DB;
    if (!$record=$DB->get_record('senscyreminder',['id'=>$id])) { return false; }
    // Pause before removing the activity; preserve the sending ledger in the engine.
    \local_senscyreminders\service::pause_course((int)$record->course);
    $DB->delete_records('senscyreminder',['id'=>$id]);
    return true;
}
function senscyreminder_reset_userdata($data) {
    \local_senscyreminders\service::pause_course((int)$data->courseid);
    return [['component'=>get_string('pluginname','mod_senscyreminder'),'item'=>'Reminders paused; history retained','error'=>false]];
}
