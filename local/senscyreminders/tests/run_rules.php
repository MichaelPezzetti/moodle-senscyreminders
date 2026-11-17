<?php
require_once(__DIR__ . '/../classes/rules.php');
use local_senscyreminders\rules;
$count = 0;
$check = function($condition, $label) use (&$count) {
    if (!$condition) {
        throw new RuntimeException($label);
    }
    $count++;
};
$c = (object)['courseid' => 14, 'period' => '2026-12', 'timezone' => 'America/New_York',
    'delaydays' => 7, 'sendhour' => 9, 'subject' => 'Reminder', 'body' => 'Hi', 'cmids' => '42,43'];
$time = fn($value) => (new DateTimeImmutable($value, new DateTimeZone($c->timezone)))->getTimestamp();
[$start, $end] = rules::window($c->period, $c->timezone);
foreach ([1, 2] as $state) {
    $check(rules::completion_counts($state, $start->getTimestamp(), $start->getTimestamp(), $end->getTimestamp()), 'Start inclusive');
    $check(rules::completion_counts($state, $end->getTimestamp()-1, $start->getTimestamp(), $end->getTimestamp()), 'Last second inclusive');
    $check(!rules::completion_counts($state, $end->getTimestamp(), $start->getTimestamp(), $end->getTimestamp()), 'End exclusive');
    $check(!rules::completion_counts($state, $start->getTimestamp()-1, $start->getTimestamp(), $end->getTimestamp()), 'Old completion excluded');
}
foreach ([0, 3, 4] as $state) {
    $check(!rules::completion_counts($state, $time('2026-12-15'), $start->getTimestamp(), $end->getTimestamp()), 'Incomplete/fail excluded');
}
$check(rules::slot($c, $time('2026-12-08 08:59:59')) === null, 'Not early');
$check(rules::slot($c, $time('2026-12-08 09:00:00')) === $time('2026-12-08 09:00:00'), 'First reminder');
$check(rules::slot($c, $time('2026-12-15 09:00:00')) === $time('2026-12-15 09:00:00'), 'Second reminder');
$check(rules::slot($c, $time('2026-12-30')) === null, 'Missed dates never catch up');
$check(rules::slot($c, $time('2027-01-01')) === null, 'No next-month send');
$check(rules::slot($c, $time('2026-11-30')) === null, 'No pre-campaign send');
$check(rules::ids('43,42,42 0,-1') === [42,43], 'Canonical activity list');
$d = clone $c; $d->cmids = '43,42';
$check(rules::fingerprint($c) === rules::fingerprint($d), 'Order does not invalidate rules');
$d->body .= ' changed';
$check(rules::fingerprint($c) !== rules::fingerprint($d), 'Message edit invalidates approval');
$check(rules::emailhash(' User@Example.com ') === rules::emailhash('user@example.com'), 'Address deduplication');
foreach (['2026-00','2026-13','2026-2','oops'] as $invalid) {
    try { rules::window($invalid, $c->timezone); $check(false, 'Invalid month accepted'); }
    catch (InvalidArgumentException $e) { $check(true, 'Month rejected'); }
}
$c->period = '2027-03'; $c->delaydays = 7;
$check(rules::slot($c, $time('2027-03-08 08:59:59')) === null, 'DST wall clock before hour');
$check(rules::slot($c, $time('2027-03-08 09:00:00')) === $time('2027-03-08 09:00:00'), 'DST wall clock due hour');
$check(rules::slot($c, $time('2027-03-15 08:59:59')) === null, 'No send before next scheduled time');
$check(rules::slot($c, $time('2027-03-15 09:00:00')) === $time('2027-03-15 09:00:00'), 'Advance on post-DST wall clock hour');
$check($time('2027-03-15 09:00:00')-$time('2027-03-08 09:00:00') === 167*3600, 'Spring DST week is 167 hours');
$first = rules::parse_start('2026-12-03 14:30', $c->timezone);
$dates = rules::generate($first, $c->timezone);
$check(count($dates) === 5 && $dates[4]['time'] === $time('2026-12-31 14:30'), 'Weekly manual start through month end');
$c->period='2026-12'; $c->senddates=json_encode($dates);
$check(rules::slot($c, $first) === $first, 'Explicit start date is due');
$dates[0]['enabled']=false; $c->senddates=json_encode($dates);
$check(rules::slot($c, $first) === null, 'Removed sending date cannot send');
$check(rules::slot($c, $dates[1]['time']) === $dates[1]['time'], 'Removing a date preserves next slot key');
$dbrow=clone $c; $dbrow->allh5p='1'; $dbrow->bodyformat='1';
$native=clone $c; $native->allh5p=1; $native->bodyformat=1;
$check(rules::fingerprint($dbrow)===rules::fingerprint($native), 'Database numeric strings preserve approval fingerprint');
$before=rules::fingerprint($c); $dates[1]['enabled']=false; $c->senddates=json_encode($dates);
$check($before!==rules::fingerprint($c), 'Removing a sending date revokes approval');
try { rules::parse_start('2026-02-30 09:00', $c->timezone); $check(false, 'Invalid date accepted'); }
catch (InvalidArgumentException $e) { $check(true, 'Invalid local date rejected'); }
$firstday=rules::parse_start('2026-12-01 09:00',$c->timezone);
$check(rules::format_start($firstday,$c->timezone)==='2026-12-01 09:00','Form date retains the leading zero on the first of a month');
$check(rules::parse_start(rules::format_start($firstday,$c->timezone),$c->timezone)===$firstday,'Default form date round-trips through strict validation');
echo "PASS: $count scheduling/completion assertions.\n";
