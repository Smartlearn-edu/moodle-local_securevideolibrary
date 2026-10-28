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
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use context_system;
use context_course;
use moodle_url;

/**
 * External API service to fetch videos for the library picker.
 *
 * @package    local_securevideolibrary
 * @copyright  2025 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_videos extends external_api {
    /**
     * Parameters definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID context', VALUE_DEFAULT, 0),
            'scope'    => new external_value(PARAM_ALPHA, 'Scope filter: my, course, all', VALUE_DEFAULT, 'all'),
            'search'   => new external_value(PARAM_TEXT, 'Search query', VALUE_DEFAULT, ''),
            'page'     => new external_value(PARAM_INT, 'Page index (0-based)', VALUE_DEFAULT, 0),
            'perpage'  => new external_value(PARAM_INT, 'Number of videos per page', VALUE_DEFAULT, 24),
        ]);
    }

    /**
     * Fetch videos matching criteria.
     *
     * @param int $courseid Course ID.
     * @param string $scope Scope filter (my, course, all).
     * @param string $search Search query string.
     * @param int $page Page offset.
     * @param int $perpage Items per page.
     * @return array
     */
    public static function execute(int $courseid = 0, string $scope = 'all', string $search = '', int $page = 0, int $perpage = 24): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'scope'    => $scope,
            'search'   => $search,
            'page'     => $page,
            'perpage'  => $perpage,
        ]);

        $courseid = $params['courseid'];
        $scope    = $params['scope'];
        $search   = trim($params['search']);
        $page     = max(0, $params['page']);
        $perpage  = min(100, max(1, $params['perpage']));

        if ($courseid > 1) {
            $context = context_course::instance($courseid);
        } else {
            $context = context_system::instance();
        }

        self::validate_context($context);
        require_capability('local/securevideolibrary:view', $context);

        $conditions = [];
        $sqlparams = [];

        if ($scope === 'my') {
            $conditions[] = 'm.uploaderid = :uid';
            $sqlparams['uid'] = $USER->id;
        } else if ($scope === 'course' && $courseid > 1) {
            $conditions[] = 'm.courseid = :cid';
            $sqlparams['cid'] = $courseid;
        } else {
            // Accessible videos.
            if (!has_capability('local/securevideolibrary:managesitewide', context_system::instance())) {
                $conditions[] = '(m.uploaderid = :uid OR m.visibility = :vis_site ' .
                    ($courseid > 1 ? 'OR (m.visibility = :vis_crs AND m.courseid = :cid)' : '') . ')';
                $sqlparams['uid'] = $USER->id;
                $sqlparams['vis_site'] = 'sitewide';
                if ($courseid > 1) {
                    $sqlparams['vis_crs'] = 'course';
                    $sqlparams['cid'] = $courseid;
                }
            }
        }

        if (!empty($search)) {
            $conditions[] = '(' . $DB->sql_like('m.title', ':search', false) . ' OR ' .
                                  $DB->sql_like('m.description', ':searchdesc', false) . ')';
            $sqlparams['search'] = '%' . $search . '%';
            $sqlparams['searchdesc'] = '%' . $search . '%';
        }

        $where = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $countsql = "SELECT COUNT(m.id) FROM {local_securevideolibrary_media} m {$where}";
        $totalcount = $DB->count_records_sql($countsql, $sqlparams);

        $sql = "SELECT m.*, u.firstname, u.lastname
                  FROM {local_securevideolibrary_media} m
                  JOIN {user} u ON u.id = m.uploaderid
                  {$where}
              ORDER BY m.id DESC";

        $records = $DB->get_records_sql($sql, $sqlparams, $page * $perpage, $perpage);

        $videos = [];
        foreach ($records as $rec) {
            $mins = floor($rec->duration / 60);
            $secs = $rec->duration % 60;
            $durationformatted = sprintf('%02d:%02d', $mins, $secs);

            $thumbnailurl = '';
            $playlisturl = '';
            if ($rec->status === 'ready') {
                $thumbnailurl = (new moodle_url('/local/securevideolibrary/serve.php', [
                    'mediaid' => $rec->id,
                    'file'    => 'thumbnail.jpg',
                ]))->out(false);

                $playlisturl = (new moodle_url('/local/securevideolibrary/serve.php', [
                    'mediaid' => $rec->id,
                    'file'    => 'playlist.m3u8',
                ]))->out(false);
            }

            $videos[] = [
                'id'                => (int)$rec->id,
                'title'             => $rec->title,
                'description'       => $rec->description ?? '',
                'duration'          => (int)$rec->duration,
                'durationformatted' => $durationformatted,
                'filesize'          => (int)$rec->filesize,
                'filesizeformatted' => $rec->filesize > 0 ? display_size($rec->filesize) : '-',
                'status'            => $rec->status,
                'thumbnailurl'      => $thumbnailurl,
                'playlisturl'       => $playlisturl,
                'uploaderfullname'  => fullname($rec),
                'timecreated'       => (int)$rec->timecreated,
            ];
        }

        return [
            'totalcount' => $totalcount,
            'videos'     => $videos,
        ];
    }

    /**
     * Return structure definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'totalcount' => new external_value(PARAM_INT, 'Total count of videos matching criteria'),
            'videos'     => new external_multiple_structure(
                new external_single_structure([
                    'id'                => new external_value(PARAM_INT, 'Media ID'),
                    'title'             => new external_value(PARAM_TEXT, 'Video title'),
                    'description'       => new external_value(PARAM_TEXT, 'Video description'),
                    'duration'          => new external_value(PARAM_INT, 'Duration in seconds'),
                    'durationformatted' => new external_value(PARAM_TEXT, 'Formatted duration (MM:SS)'),
                    'filesize'          => new external_value(PARAM_INT, 'Size in bytes'),
                    'filesizeformatted' => new external_value(PARAM_TEXT, 'Formatted filesize'),
                    'status'            => new external_value(PARAM_ALPHA, 'Status (queued, processing, ready, failed)'),
                    'thumbnailurl'      => new external_value(PARAM_URL, 'URL to thumbnail poster image'),
                    'playlisturl'       => new external_value(PARAM_URL, 'URL to HLS master playlist'),
                    'uploaderfullname'  => new external_value(PARAM_TEXT, 'Full name of uploader'),
                    'timecreated'       => new external_value(PARAM_INT, 'Timestamp created'),
                ])
            ),
        ]);
    }
}
