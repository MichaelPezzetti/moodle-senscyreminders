<?php
defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot.'/course/moodleform_mod.php');
class mod_senscyreminder_mod_form extends moodleform_mod {
    public function definition() {
        $m=$this->_form;
        $m->addElement('header','general',get_string('general','form'));
        $m->addElement('text','name',get_string('name'),['size'=>60,'maxlength'=>255]);
        $m->setType('name',PARAM_TEXT); $m->setDefault('name','Training reminders');
        $m->addRule('name',null,'required',null,'client');
        $m->addElement('static','instructions','','Save and display to edit the message, logo and weekly dates, preview recipients, then Start. This manages eligible learners across the course; the training activities determine their access. Saving never starts mail. You can hide this administrative activity from students. Hiding does not stop email; use Stop All or Pause.');
        $this->standard_coursemodule_elements();
        $m->setDefault('visible',0);
        $m->setDefault('completion',COMPLETION_TRACKING_NONE);
        $this->add_action_buttons();
    }
    public function validation($data,$files) {
        global $DB;
        $errors=parent::validation($data,$files);
        $existing=$DB->get_record('senscyreminder',['course'=>$this->_course->id]);
        if ($existing && (int)$existing->id!==(int)($data['instance']??0)) {
            $errors['name']=get_string('duplicateactivity','mod_senscyreminder');
        }
        if (!$this->_course->enablecompletion) { $errors['name']='Enable course completion tracking first.'; }
        return $errors;
    }
}
