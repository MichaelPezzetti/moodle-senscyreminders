<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_senscyreminders;

/** Pure scheduling and completion rules. End boundaries are exclusive. */
final class rules {
    public static function window(string $period, string $timezone): array {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $period)) {
            throw new \InvalidArgumentException('Use YYYY-MM for the campaign month.');
        }
        $tz = new \DateTimeZone($timezone);
        $start = new \DateTimeImmutable($period . '-01 00:00:00', $tz);
        return [$start, $start->modify('first day of next month')];
    }

    public static function slot(object $campaign, int $now): ?int {
        [$start, $end] = self::window($campaign->period, $campaign->timezone);
        if ($now < $start->getTimestamp() || $now >= $end->getTimestamp()) {
            return null;
        }
        foreach (self::schedule($campaign) as $date) {
            $due = (new \DateTimeImmutable('@' . $date['time']))->setTimezone(new \DateTimeZone($campaign->timezone));
            $dayend = $due->modify('tomorrow')->setTime(0, 0)->getTimestamp();
            if ($date['enabled'] && $now >= $date['time'] && $now < $dayend) {
                return $date['time']; // Timestamp is a stable deduplication key even when a date is removed.
            }
        }
        return null; // No catch-up on unscheduled days.
    }

    public static function parse_start(string $value, string $timezone): int {
        $value = str_replace('T', ' ', trim($value));
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $value, new \DateTimeZone($timezone));
        if (!$date || $date->format('Y-m-d H:i') !== $value) {
            throw new \InvalidArgumentException('Use YYYY-MM-DD HH:MM in the selected timezone.');
        }
        return $date->getTimestamp();
    }

    /** Input values must retain zero-padding; Moodle's userdate() may remove it for display. */
    public static function format_start(int $timestamp, string $timezone): string {
        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone($timezone))->format('Y-m-d H:i');
    }

    public static function generate(int $first, string $timezone): array {
        $date = (new \DateTimeImmutable('@' . $first))->setTimezone(new \DateTimeZone($timezone));
        [, $end] = self::window($date->format('Y-m'), $timezone);
        $dates = [];
        while ($date < $end) {
            $dates[] = ['time' => $date->getTimestamp(), 'enabled' => true];
            $date = $date->modify('+7 days');
        }
        return $dates;
    }

    public static function schedule(object $campaign): array {
        if (isset($campaign->senddates)) {
            $dates = json_decode($campaign->senddates, true);
            if (!is_array($dates)) {
                throw new \InvalidArgumentException('Invalid sending dates.');
            }
            return $dates;
        }
        // Supports interpreting v0.1 rows during migration; all installed v0.2 rows have explicit dates.
        [$start] = self::window($campaign->period, $campaign->timezone);
        return self::generate($start->modify('+' . (int)$campaign->delaydays . ' days')->setTime((int)$campaign->sendhour, 0)->getTimestamp(), $campaign->timezone);
    }

    public static function completion_counts(int $state, int $timestamp, int $start, int $end): bool {
        return in_array($state, [1, 2], true) && $timestamp >= $start && $timestamp < $end;
    }

    public static function ids(string $value): array {
        $ids = array_map('intval', preg_split('/[\s,]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY));
        $ids = array_values(array_unique(array_filter($ids, fn($id) => $id > 0)));
        sort($ids);
        return $ids;
    }

    public static function fingerprint(object $campaign): string {
        $data = [];
        foreach (['courseid', 'period', 'timezone', 'delaydays', 'sendhour', 'subject', 'body'] as $name) {
            $data[$name] = (string)$campaign->$name;
        }
        $data['cmids'] = self::ids($campaign->cmids);
        foreach (['bodyformat' => 2, 'allh5p' => 0, 'trainingurl' => '', 'logourl' => '', 'logohash' => ''] as $key => $default) {
            $data[$key] = (string)($campaign->$key ?? $default);
        }
        $data['dates'] = self::schedule($campaign);
        return hash('sha256', json_encode($data, JSON_UNESCAPED_SLASHES));
    }

    public static function emailhash(string $email): string {
        return hash('sha256', strtolower(trim($email)));
    }
}
