<?php
namespace local_senscyreminders\privacy;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

final class provider implements \core_privacy\local\metadata\provider,
        \core_privacy\local\request\plugin\provider, \core_privacy\local\request\core_userlist_provider {
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_scr_log', [
            'userid' => 'privacy:metadata:userid', 'campaignid' => 'privacy:metadata:data',
            'emailhash' => 'privacy:metadata:data', 'slot' => 'privacy:metadata:data',
            'status' => 'privacy:metadata:data', 'reason' => 'privacy:metadata:data',
            'evidencecmid' => 'privacy:metadata:data', 'evidencetime' => 'privacy:metadata:data',
            'evidencekind' => 'privacy:metadata:data', 'evidenceid' => 'privacy:metadata:data',
            'timecreated' => 'privacy:metadata:data', 'processedat' => 'privacy:metadata:data',
        ], 'privacy:metadata:log');
        $collection->add_database_table('local_scr_campaigns', [
            'approvedby' => 'privacy:metadata:userid', 'approvedat' => 'privacy:metadata:data',
        ], 'privacy:metadata:approvals');
        return $collection;
    }
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;
        $list = new contextlist();
        if ($DB->record_exists('local_scr_log', ['userid' => $userid])
                || $DB->record_exists('local_scr_campaigns', ['approvedby' => $userid])) {
            $list->add_system_context();
        }
        return $list;
    }
    public static function get_users_in_context(userlist $userlist): void {
        if ($userlist->get_context()->contextlevel === CONTEXT_SYSTEM) {
            $userlist->add_from_sql('userid', 'SELECT userid FROM {local_scr_log}', []);
            $userlist->add_from_sql('approvedby', 'SELECT approvedby FROM {local_scr_campaigns} WHERE approvedby>0', []);
        }
    }
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        if (!in_array(\context_system::instance()->id, $contextlist->get_contextids())) {
            return;
        }
        $userid = $contextlist->get_user()->id;
        $logs = array_values($DB->get_records('local_scr_log', ['userid' => $userid]));
        $approvals = array_values($DB->get_records('local_scr_campaigns', ['approvedby' => $userid], '',
            'id,courseid,period,approvedby,approvedat'));
        writer::with_context(\context_system::instance())->export_data(
            [get_string('privacy:records', 'local_senscyreminders')], (object)['decisions' => $logs, 'approvals' => $approvals]);
    }
    private static function delete_user(int $userid): void {
        global $DB;
        // Do not make a still-active learner eligible for a duplicate by erasing their deduplication history.
        $DB->execute('UPDATE {local_scr_campaigns} SET enabled=0,approvedhash=:empty WHERE id IN
            (SELECT campaignid FROM {local_scr_log} WHERE userid=:userid)', ['empty' => '', 'userid' => $userid]);
        $DB->delete_records('local_scr_log', ['userid' => $userid]);
        // Removing the audit actor also revokes approval rather than leaving unattributed live sending.
        $DB->execute('UPDATE {local_scr_campaigns} SET approvedby=0,approvedat=0,approvedhash=:empty,enabled=0 WHERE approvedby=:u',
            ['empty' => '', 'u' => $userid]);
    }
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if ($context->contextlevel === CONTEXT_SYSTEM) {
            $DB->delete_records('local_scr_log');
            $DB->execute('UPDATE {local_scr_campaigns} SET approvedby=0,approvedat=0,approvedhash=:empty,enabled=0', ['empty' => '']);
        }
    }
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        if (in_array(\context_system::instance()->id, $contextlist->get_contextids())) {
            self::delete_user((int)$contextlist->get_user()->id);
        }
    }
    public static function delete_data_for_users(approved_userlist $userlist): void {
        if ($userlist->get_context()->contextlevel === CONTEXT_SYSTEM) {
            foreach ($userlist->get_userids() as $userid) {
                self::delete_user((int)$userid);
            }
        }
    }
}
