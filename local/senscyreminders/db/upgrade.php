<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_local_senscyreminders_upgrade($oldversion): bool {
    global $DB;
    if ($oldversion < 2026111600) {
        $manager = $DB->get_manager();
        $table = new xmldb_table('local_scr_campaigns');
        $fields = [
            new xmldb_field('firstsend', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, 0),
            new xmldb_field('senddates', XMLDB_TYPE_TEXT, null, null, null),
            new xmldb_field('bodyformat', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, FORMAT_PLAIN),
            new xmldb_field('allh5p', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, 0),
            new xmldb_field('trainingurl', XMLDB_TYPE_CHAR, '1024', null, XMLDB_NOTNULL),
            new xmldb_field('logourl', XMLDB_TYPE_CHAR, '1024', null, XMLDB_NOTNULL),
            new xmldb_field('logohash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL),
            new xmldb_field('approvedbranch', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL),
        ];
        foreach ($fields as $field) {
            if (!$manager->field_exists($table, $field)) {
                $manager->add_field($table, $field);
            }
        }
        // Widen before converting v0.1 week indexes to stable date keys. Never erase send history.
        $logtable = new xmldb_table('local_scr_log');
        $indexes = [new xmldb_index('userslot', XMLDB_INDEX_UNIQUE, ['campaignid', 'userid', 'slot']),
            new xmldb_index('addressslot', XMLDB_INDEX_NOTUNIQUE, ['campaignid', 'slot', 'emailhash'])];
        foreach ($indexes as $index) {
            if ($manager->index_exists($logtable, $index)) { $manager->drop_index($logtable, $index); }
        }
        $manager->change_field_precision(new xmldb_table('local_scr_log'),
            new xmldb_field('slot', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, 0));
        foreach ($indexes as $index) { $manager->add_index($logtable, $index); }
        $manager->change_field_precision($table,
            new xmldb_field('cursorslot', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, -1));
        foreach ($DB->get_records('local_scr_campaigns') as $campaign) {
            $dates = \local_senscyreminders\rules::schedule($campaign);
            // Newly added nullable field must be omitted to read the legacy schedule.
            if ($campaign->senddates === null) {
                unset($campaign->senddates);
                $dates = \local_senscyreminders\rules::schedule($campaign);
            }
            $campaign->senddates = json_encode($dates);
            $campaign->firstsend = $dates[0]['time'] ?? 0;
            foreach ($dates as $index => $date) {
                $DB->set_field('local_scr_log', 'slot', $date['time'], ['campaignid' => $campaign->id, 'slot' => $index]);
            }
            $campaign->enabled = 0;
            $campaign->approvedhash = '';
            $campaign->approvedbranch = '';
            $campaign->approvedby = 0;
            $campaign->approvedat = 0;
            $campaign->cursorid = 0;
            $campaign->cursorslot = -1;
            $DB->update_record('local_scr_campaigns', $campaign);
        }
        set_config('enabled', 0, 'local_senscyreminders');
        upgrade_plugin_savepoint(true, 2026111600, 'local', 'senscyreminders');
    }
    if ($oldversion < 2026111601) {
        set_config('enabled', 0, 'local_senscyreminders');
        $DB->execute('UPDATE {local_scr_campaigns} SET enabled=0,approvedhash=:empty', ['empty' => '']);
        upgrade_plugin_savepoint(true, 2026111601, 'local', 'senscyreminders');
    }
    if ($oldversion < 2026111700) {
        set_config('enabled', 0, 'local_senscyreminders');
        $DB->execute('UPDATE {local_scr_campaigns} SET enabled=0,approvedhash=:empty', ['empty' => '']);
        upgrade_plugin_savepoint(true, 2026111700, 'local', 'senscyreminders');
    }
    return true;
}

