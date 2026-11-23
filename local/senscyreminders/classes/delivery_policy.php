<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_senscyreminders;

/** Bounded DEV mail policy. Never removes or overrides Moodle's mail protections. */
final class delivery_policy {
    const LIMIT = 10;
    const KEYS = ['devtestenabled','devtesturl','devtestemail','devtestcourse','devtestusers','devtestexpires'];

    public static function read(): array {
        global $DB;
        $saved = $DB->get_records('config_plugins', ['plugin'=>'local_senscyreminders']);
        $values = array_fill_keys(self::KEYS, '');
        foreach ($saved as $row) {
            if (array_key_exists($row->name,$values)) { $values[$row->name]=trim((string)$row->value); }
        }
        $configured = false;
        foreach ($values as $key=>$value) {
            if (in_array($key,['devtestenabled','devtestcourse'],true) ? $value!=='' && $value!=='0' : $value!=='') { $configured=true; }
        }
        return ['configured'=>$configured,'values'=>$values];
    }

    public static function signature(?array $p=null): string {
        global $CFG;
        $p=$p??self::read();
        return hash('sha256',json_encode(['site'=>rtrim($CFG->wwwroot,'/'),'policy'=>$p],JSON_UNESCAPED_SLASHES));
    }

    public static function ids(?array $p=null): array {
        return rules::ids(($p??self::read())['values']['devtestusers']);
    }

    public static function reason(?object $campaign=null, ?object $user=null, ?int $now=null, ?array $snapshot=null): string {
        global $CFG;
        $p=$snapshot??self::read();$v=$p['values'];$now=$now??time();
        if (!$p['configured']) {
            return !empty($CFG->divertallemailsto)||!empty($CFG->divertallemailsexcept) ? 'mail_diversion_blocked' : '';
        }
        if ($v['devtestenabled']!=='1') { return 'devtest_disabled'; }
        if (!preg_match('~^https?://~i',$v['devtesturl']) || rtrim($v['devtesturl'],'/')!==rtrim($CFG->wwwroot,'/')) {
            return 'devtest_wrong_site';
        }
        $email=$v['devtestemail'];$divert=$CFG->divertallemailsto??'';
        if (!filter_var($email,FILTER_VALIDATE_EMAIL) || str_ends_with(strtolower($email),'.invalid')
                || !is_string($divert) || !filter_var(trim($divert),FILTER_VALIDATE_EMAIL)
                || trim($divert)!==$email || !empty($CFG->divertallemailsexcept)) {
            return 'devtest_diversion_mismatch';
        }
        if (!ctype_digit($v['devtestcourse']) || (int)$v['devtestcourse']<=1) { return 'devtest_scope_invalid'; }
        if (!preg_match('/^\d+(?:[\s,]+\d+)*$/D',$v['devtestusers'])) { return 'devtest_scope_invalid'; }
        $ids=self::ids($p);
        if (!$ids || count($ids)>self::LIMIT || in_array(0,array_map('intval',preg_split('/[\s,]+/',$v['devtestusers'])),true)) {
            return 'devtest_scope_invalid';
        }
        try { $expires=rules::parse_start($v['devtestexpires'],'America/New_York'); }
        catch (\Exception $e) { return 'devtest_expiry_invalid'; }
        if ($expires<=$now) { return 'devtest_expired'; }
        if ($expires>$now+2*DAYSECS) { return 'devtest_expiry_invalid'; }
        if ($campaign && (int)$campaign->courseid!==(int)$v['devtestcourse']) { return 'devtest_outside_scope'; }
        if ($user && !in_array((int)$user->id,$ids,true)) { return 'devtest_outside_scope'; }
        return '';
    }

    public static function budget(object $campaign, int $exclude=0): bool {
        global $DB;
        if (!self::read()['configured']) { return true; }
        return $DB->count_records_select(service::LOG,
            'campaignid=:c AND id<>:exclude AND status IN (:a,:b,:d)',
            ['c'=>$campaign->id,'exclude'=>$exclude,'a'=>'reserved','b'=>'submitted','d'=>'held'])<self::LIMIT;
    }

    public static function describe(): string {
        global $CFG;
        $p=self::read();
        if (!$p['configured']) {
            return !empty($CFG->noemailever) ? 'Site email is disabled (noemailever). No mail will be submitted.' :
                (self::reason() ? 'Moodle email diversion is active. Configure bounded DEV diversion testing in Settings to test safely.' : '');
        }
        $v=$p['values'];$reason=self::reason();
        $messages=[
            'devtest_disabled'=>'DEV test sending is disabled. Clear all DEV test fields and review again before returning to normal sending.',
            'devtest_wrong_site'=>'DEV test blocked: the site URL must exactly match this installation’s root URL.',
            'devtest_diversion_mismatch'=>'DEV test blocked: Moodle must divert ALL mail to the one configured test mailbox, with no diversion exceptions.',
            'devtest_scope_invalid'=>'DEV test blocked: specify one course ID and 1–10 positive learner IDs.',
            'devtest_expiry_invalid'=>'DEV test blocked: choose an expiry within the next 48 hours, using YYYY-MM-DD HH:MM in America/New_York.',
            'devtest_expired'=>'DEV test expired. Sending is stopped until settings are reviewed and the run is explicitly resumed.',
        ];
        if ($reason) { return $messages[$reason]??'DEV test sending is blocked: '.$reason; }
        $text='DEV DIVERSION TEST: only course '.$v['devtestcourse'].' and learner IDs '.implode(', ',self::ids())
            .'. All test messages are addressed to '.$v['devtestemail'].'. Maximum 10 attempts per run. Expires '
            .$v['devtestexpires'].' America/New_York. Changing/removing diversion or these settings stops approved sending.';
        if (!empty($CFG->noemailever)) { $text.=' SITE EMAIL IS ALSO DISABLED: noemailever remains respected; no messages can be submitted.'; }
        return $text;
    }
}
