<?php
namespace local_senscyreminders;

/** Only the explicitly uploaded branding image is public; no learner files are exposed. */
final class assets {
    public static function options(): array {
        return ['subdirs' => 0, 'maxfiles' => 1, 'maxbytes' => 524288, 'accepted_types' => ['.png','.jpg','.jpeg','.gif']];
    }
    public static function logo(int $id): ?\stored_file {
        $files = get_file_storage()->get_area_files(\context_system::instance()->id,
            'local_senscyreminders', 'logo', $id, 'id', false);
        return $files ? reset($files) : null;
    }
    public static function valid(\stored_file $file): bool {
        if ($file->get_filesize() > 524288 || !in_array($file->get_mimetype(), ['image/png','image/jpeg','image/gif'], true)) {
            return false;
        }
        $info = @getimagesizefromstring($file->get_content());
        return $info && $info[0] <= 4000 && $info[1] <= 4000 && in_array($info['mime'], ['image/png','image/jpeg','image/gif'], true);
    }
    public static function hash(int $id): string {
        $file = self::logo($id);
        return $file ? hash('sha256', $file->get_contenthash() . '/' . $file->get_filename()) : '';
    }
    public static function url(object $campaign): string {
        $file = self::logo((int)($campaign->id ?? 0));
        if ($file) {
            if (!self::valid($file)) {
                throw new \moodle_exception('invalidlogo', 'local_senscyreminders');
            }
            return \moodle_url::make_pluginfile_url($file->get_contextid(), 'local_senscyreminders', 'logo',
                $campaign->id, '/', $file->get_filename())->out(false);
        }
        return $campaign->logourl ?? '';
    }
}
