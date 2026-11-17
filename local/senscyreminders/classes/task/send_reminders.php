<?php
namespace local_senscyreminders\task;
final class send_reminders extends \core\task\scheduled_task {
    public function get_name(): string {
        return get_string('taskname', 'local_senscyreminders');
    }
    public function execute(): void {
        global $DB;
        if (!\local_senscyreminders\service::global_enabled()) {
            return;
        }
        foreach ($DB->get_records('local_scr_campaigns', ['enabled' => 1], 'id') as $campaign) {
            try {
                $result = \local_senscyreminders\service::process((int)$campaign->id);
                mtrace('SensCy campaign ' . $campaign->id . ': ' . json_encode($result));
            } catch (\Throwable $e) {
                mtrace('SensCy campaign ' . $campaign->id . ' held: ' . get_class($e));
                // A malformed campaign must not prevent independent courses from processing.
            }
        }
    }
}
