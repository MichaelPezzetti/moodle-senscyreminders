<?php
namespace local_senscyreminders;

final class message {
    /** Preserve the link token while the HTML sanitizer validates real URI attributes. */
    public static function clean_template(string $body): string {
        $sentinel = 'https://senscy.invalid/reminder-training-link';
        $body = str_ireplace(['{courselink}', '%7Bcourselink%7D'], $sentinel, $body);
        return str_replace($sentinel, '{courselink}', clean_text($body, FORMAT_HTML));
    }
    /** HTML-safe substitutions, sanitized HTML and a readable text alternative with link destinations. */
    public static function render(object $campaign, object $user): array {
        global $DB;
        $course = $DB->get_record('course', ['id' => $campaign->courseid], '*', MUST_EXIST);
        $replace = [
            '{firstname}' => $user->firstname, '{lastname}' => $user->lastname,
            '%userfirstname%' => $user->firstname, '%userlastname%' => $user->lastname,
            '{coursename}' => strip_tags(format_string($course->fullname, true, ['context' => \context_course::instance($course->id)])),
            '{courselink}' => !empty($campaign->trainingurl) ? $campaign->trainingurl :
                (new \moodle_url('/course/view.php', ['id' => $course->id]))->out(false),
            '{month}' => $campaign->period,
        ];
        $subject = str_replace(["\r", "\n"], ' ', strip_tags(strtr($campaign->subject, $replace)));
        $format = (int)($campaign->bodyformat ?? FORMAT_PLAIN);
        if ($format === (int)FORMAT_HTML) {
            $escaped = array_map(fn($value) => s($value), $replace);
            $html = format_text(strtr($campaign->body, $escaped), FORMAT_HTML,
                ['context' => \context_system::instance(), 'filter' => false, 'noclean' => false, 'para' => false, 'overflowdiv' => false]);
        } else {
            $html = nl2br(s(strtr($campaign->body, $replace)));
        }
        $logo = assets::url($campaign);
        if ($logo !== '') {
            $html = \html_writer::div(\html_writer::empty_tag('img', ['src' => $logo, 'alt' => 'SensCy', 'width' => 200,
                'style' => 'max-width:200px;height:auto;']), '', ['style' => 'margin-bottom:20px;']) . $html;
        }
        return [$subject, html_to_text($html, 79), $html];
    }
}
