<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_securevideolibrary\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use context_system;
use moodle_url;

/**
 * External API service to poll current video transcode status.
 *
 * @package    local_securevideolibrary
 * @copyright  2025 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_video_status extends external_api {
    /**
     * Parameters definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'mediaid' => new external_value(PARAM_INT, 'The media ID to check'),
        ]);
    }

    /**
     * Fetch status for a video.
     *
     * @param int $mediaid Media ID.
     * @return array
     */
    public static function execute(int $mediaid): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'mediaid' => $mediaid,
        ]);

        $mediaid = $params['mediaid'];

        $media = $DB->get_record('local_securevideolibrary_media', ['id' => $mediaid], '*', MUST_EXIST);

        $context = context_system::instance();
        self::validate_context($context);

        if (!local_securevideolibrary_can_access_media($media)) {
            throw new \moodle_exception('cannotaccessvideo', 'local_securevideolibrary');
        }

        $mins = floor($media->duration / 60);
        $secs = $media->duration % 60;
        $durationformatted = sprintf('%02d:%02d', $mins, $secs);

        $thumbnailurl = '';
        $playlisturl = '';
        if ($media->status === 'ready') {
            $thumbnailurl = (new moodle_url('/local/securevideolibrary/serve.php', [
                'mediaid' => $media->id,
                'file'    => 'thumbnail.jpg',
            ]))->out(false);

            $playlisturl = (new moodle_url('/local/securevideolibrary/serve.php', [
                'mediaid' => $media->id,
                'file'    => 'playlist.m3u8',
            ]))->out(false);
        }

        return [
            'id'                => (int)$media->id,
            'status'            => $media->status,
            'duration'          => (int)$media->duration,
            'durationformatted' => $durationformatted,
            'filesize'          => (int)$media->filesize,
            'filesizeformatted' => $media->filesize > 0 ? display_size($media->filesize) : '-',
            'errormessage'      => $media->errormessage ?? '',
            'thumbnailurl'      => $thumbnailurl,
            'playlisturl'       => $playlisturl,
        ];
    }

    /**
     * Return structure definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'id'                => new external_value(PARAM_INT, 'Media ID'),
            'status'            => new external_value(PARAM_ALPHA, 'Status string'),
            'duration'          => new external_value(PARAM_INT, 'Duration in seconds'),
            'durationformatted' => new external_value(PARAM_TEXT, 'Formatted duration'),
            'filesize'          => new external_value(PARAM_INT, 'Filesize in bytes'),
            'filesizeformatted' => new external_value(PARAM_TEXT, 'Formatted filesize'),
            'errormessage'      => new external_value(PARAM_TEXT, 'Error message if failed'),
            'thumbnailurl'      => new external_value(PARAM_URL, 'Thumbnail URL'),
            'playlisturl'       => new external_value(PARAM_URL, 'Playlist URL'),
        ]);
    }
}
