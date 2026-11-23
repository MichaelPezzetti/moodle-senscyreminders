<?php
// Included by the rollback-only localhost integration harness. No real mail transport.
use local_senscyreminders\delivery_policy;
use local_senscyreminders\service;

$savedpolicy=delivery_policy::read();
$saveddivert=$CFG->divertallemailsto??'';
$savedexcept=$CFG->divertallemailsexcept??'';
$putpolicy=function($values) use ($DB) {
    foreach ($values as $key=>$value) {
        $where=['plugin'=>'local_senscyreminders','name'=>$key];
        if ($DB->record_exists('config_plugins',$where)) { $DB->set_field('config_plugins','value',$value,$where); }
        else { $DB->insert_record('config_plugins',(object)($where+['value'=>$value])); }
    }
};
try {
    $devnow=(new DateTimeImmutable('2027-08-08 09:00',new DateTimeZone('America/New_York')))->getTimestamp();
    $valid=['devtestenabled'=>'1','devtesturl'=>$CFG->wwwroot,'devtestemail'=>'capture@example.test',
        'devtestcourse'=>(string)$course->id,
        'devtestusers'=>implode(',',[$users['old']->id,$users['pass']->id,$users['fail']->id]),
        'devtestexpires'=>'2027-08-09 09:00'];
    $putpolicy($valid);$CFG->divertallemailsto='capture@example.test';$CFG->divertallemailsexcept='';
    $dev=$newcampaign('2027-08');
    $check(delivery_policy::reason($dev,null,$devnow)==='', 'Valid bounded DEV configuration accepted');
    $check(count(service::candidates($dev,0,100,$devnow))===3, 'DEV preview only enumerates allowlisted enrolled users');
    $check(service::evaluate($dev,$users['pageonly'],$devnow)['reason']==='devtest_outside_scope', 'Non-allowlisted learner fails closed');
    $completion($users['pass'],$cmid,2,$devnow-60);
    $devcalls=[];
    $capture=function($c,$u) use (&$devcalls) { $devcalls[]=$u->id;return true; };
    $totals=service::process($dev->id,$devnow,$capture);
    $check($totals['submitted']===2 && $totals['suppressed']===1, 'DEV dispatch still suppresses this-month completed H5P');
    $check($devcalls===[$users['old']->id,$users['fail']->id], 'DEV dispatch only reaches pending allowlisted users');
    $check($DB->count_records(service::LOG,['campaignid'=>$dev->id,'reason'=>'dev_diversion_accepted'])===2, 'Test submissions have explicit DEV history reason');
    service::process($dev->id,$devnow,$capture);
    $check(count($devcalls)===2, 'Rerun does not duplicate test submissions');
    foreach ([
        ['devtesturl','https://different.example.test','devtest_wrong_site'],
        ['devtestcourse','1','devtest_scope_invalid'],
        ['devtestusers','0,2','devtest_scope_invalid'],
        ['devtestusers','-1,2','devtest_scope_invalid'],
        ['devtestusers','1,2,3,4,5,6,7,8,9,10,11','devtest_scope_invalid'],
        ['devtestexpires','2027-08-08 08:59','devtest_expired'],
        ['devtestexpires','2027-08-11 09:00','devtest_expiry_invalid'],
        ['devtestenabled','0','devtest_disabled'],
    ] as [$key,$value,$expected]) {
        $putpolicy([$key=>$value]);
        $check(delivery_policy::reason($dev,null,$devnow)===$expected,'DEV rejects unsafe setting '.$key.'='.$value);
        $putpolicy([$key=>$valid[$key]]);
    }
    $other=clone $dev;$other->courseid++;
    $check(delivery_policy::reason($other,null,$devnow)==='devtest_outside_scope', 'Another course cannot use test approval');
    foreach (['','other@example.test','capture@example.test,other@example.test'] as $address) {
        $CFG->divertallemailsto=$address;
        $check(delivery_policy::reason($dev,null,$devnow)==='devtest_diversion_mismatch', 'Missing or changed global diversion blocks DEV');
    }
    $CFG->divertallemailsto=$valid['devtestemail'];$CFG->divertallemailsexcept='*@example.test';
    $check(delivery_policy::reason($dev,null,$devnow)==='devtest_diversion_mismatch', 'Any diversion exception blocks DEV');
    $CFG->divertallemailsexcept='';
    $putpolicy(['devtestexpires'=>'2027-09-09 09:00']);
    $sepnow=(new DateTimeImmutable('2027-09-08 09:00',new DateTimeZone('America/New_York')))->getTimestamp();
    $sep=$newcampaign('2027-09');$before=count($devcalls);
    $mutate=function($c,$u) use (&$devcalls,$putpolicy) {
        $devcalls[]=$u->id;$putpolicy(['devtestenabled'=>'0']);return true;
    };
    service::process($sep->id,$sepnow,$mutate);
    $check(count($devcalls)===$before+1, 'Disabling test mode mid-batch stops after first submission');
    $check(service::runtime_reason($sep,$sepnow)==='delivery_policy_changed', 'Changed settings invalidate the reviewed delivery policy');
    $putpolicy(['devtestenabled'=>'1','devtestexpires'=>'2027-10-09 09:00']);
    $octnow=(new DateTimeImmutable('2027-10-08 09:00',new DateTimeZone('America/New_York')))->getTimestamp();
    $oct=$newcampaign('2027-10');$before=count($devcalls);
    service::process($oct->id,$octnow,function($c,$u) use (&$devcalls) {
        global $CFG;$devcalls[]=$u->id;$CFG->divertallemailsto='';return true;
    });
    $check(count($devcalls)===$before+1, 'Removing diversion mid-batch stops further submissions');
    $CFG->divertallemailsto=$valid['devtestemail'];
    $putpolicy(['devtestexpires'=>'2027-11-09 09:00']);
    $novnow=(new DateTimeImmutable('2027-11-08 09:00',new DateTimeZone('America/New_York')))->getTimestamp();
    $nov=$newcampaign('2027-11');
    for ($i=0;$i<9;$i++) {
        $DB->insert_record(service::LOG,(object)['campaignid'=>$nov->id,'userid'=>0,'slot'=>$i+100,
            'emailhash'=>hash('sha256','budget'.$i),'status'=>'held','reason'=>'fixture',
            'timecreated'=>$novnow-10,'processedat'=>$novnow-10]);
    }
    $before=count($devcalls);service::process($nov->id,$novnow,$capture);
    $check(count($devcalls)===$before+1, 'Ten-attempt cap permits exactly the tenth reservation');
    $check(!delivery_policy::budget($nov), 'Held attempts count toward ten-attempt limit');
    service::process($nov->id,$novnow,$capture);
    $check(count($devcalls)===$before+1, 'Ten-attempt cap persists across task executions');
    $putpolicy(array_fill_keys(delivery_policy::KEYS,''));$CFG->divertallemailsto='';
    $check(service::runtime_reason($nov,$novnow)==='delivery_policy_changed', 'Clearing test mode never silently converts reviewed test run to live sending');
} finally {
    $putpolicy($savedpolicy['values']);
    $CFG->divertallemailsto=$saveddivert;$CFG->divertallemailsexcept=$savedexcept;
}
