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

/**
 * Management interface for local_securevideolibrary.
 *
 * @package    local_securevideolibrary
 * @copyright  2025 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$courseid = optional_param('courseid', 0, PARAM_INT);
$action   = optional_param('action', '', PARAM_ALPHA);
$deleteid = optional_param('delete', 0, PARAM_INT);
$tab      = optional_param('tab', 'my', PARAM_ALPHA);
$search   = optional_param('search', '', PARAM_TEXT);

if ($courseid > 1) {
    $course = get_course($courseid);
    require_login($course);
    $context = context_course::instance($courseid);
    $pageurl = new moodle_url('/local/securevideolibrary/manage.php', ['courseid' => $courseid]);
} else {
    require_login();
    $context = context_system::instance();
    $pageurl = new moodle_url('/local/securevideolibrary/manage.php');
}

require_capability('local/securevideolibrary:view', $context);

$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_title(get_string('managevideos', 'local_securevideolibrary'));
$PAGE->set_heading(get_string('pluginname', 'local_securevideolibrary'));

// Handle Delete Request.
if ($deleteid > 0 && confirm_sesskey()) {
    $media = $DB->get_record('local_securevideolibrary_media', ['id' => $deleteid], '*', MUST_EXIST);

    $canmanage = has_capability('local/securevideolibrary:managesitewide', context_system::instance()) ||
                 ((int)$media->uploaderid === (int)$USER->id) ||
                 (!empty($media->courseid) && has_capability('local/securevideolibrary:managecourse', context_course::instance($media->courseid)));

    if (!$canmanage) {
        throw new moodle_exception('cannotaccessvideo', 'local_securevideolibrary');
    }

    \local_securevideolibrary\storage::delete_media_files((int)$media->id);
    $DB->delete_records('local_securevideolibrary_media', ['id' => $media->id]);

    redirect($pageurl, get_string('deletesuccess', 'local_securevideolibrary'), null, \core\output\notification::NOTIFY_SUCCESS);
}

// Upload Form.
$canupload = has_capability('local/securevideolibrary:upload', $context);
$uploadform = null;
$showuploadmodal = false;

if ($canupload) {
    $uploadform = new \local_securevideolibrary\form\upload_form($pageurl, [
        'courseid' => $courseid,
        'context'  => $context,
    ]);

    if ($uploadform->is_cancelled()) {
        redirect($pageurl);
    } else if ($data = $uploadform->get_data()) {
        $draftitemid = $data->videofile;
        $usercontext = context_user::instance($USER->id);
        $fs = get_file_storage();
        $files = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'id DESC', false);

        if (empty($files)) {
            \core\notification::error(get_string('err_uploadempty', 'local_securevideolibrary'));
        } else {
            $file = reset($files);
            $filename = clean_filename($file->get_filename());
            $tempdir = \local_securevideolibrary\storage::get_temp_dir();
            $temppath = $tempdir . '/' . uniqid('raw_', true) . '_' . $filename;

            $file->copy_content_to($temppath);

            $media = new stdClass();
            $media->title          = $data->title;
            $media->description    = $data->description;
            $media->duration       = 0;
            $media->filesize       = 0;
            $media->uploaderid     = (int)$USER->id;
            $media->courseid       = $courseid;
            $media->contextid      = $context->id;
            $media->visibility         = $data->visibility;
            $media->bitrateprofile     = $data->bitrateprofile;
            $media->compressionprofile = $data->compressionprofile ?? 'balanced';
            $media->status         = LOCAL_SECUREVIDEOLIBRARY_STATUS_QUEUED;
            $media->timecreated    = time();
            $media->timemodified   = time();

            $mediaid = $DB->insert_record('local_securevideolibrary_media', $media);

            // Queue the background transcoding adhoc task.
            $task = new \local_securevideolibrary\task\transcode_video();
            $task->set_custom_data([
                'mediaid'      => $mediaid,
                'tempfilepath' => $temppath,
            ]);
            \core\task\manager::queue_adhoc_task($task);

            redirect($pageurl, get_string('uploadsuccess', 'local_securevideolibrary'), null, \core\output\notification::NOTIFY_SUCCESS);
        }
    }
}

// Fetch Videos.
$conditions = [];
$params = [];

if ($tab === 'my') {
    $conditions[] = 'm.uploaderid = :uploaderid';
    $params['uploaderid'] = $USER->id;
} else if ($tab === 'course' && $courseid > 1) {
    $conditions[] = 'm.courseid = :courseid';
    $params['courseid'] = $courseid;
} else {
    // All accessible.
    if (!has_capability('local/securevideolibrary:managesitewide', context_system::instance())) {
        $conditions[] = '(m.uploaderid = :uid OR m.visibility = :vis_site ' .
            ($courseid > 1 ? 'OR (m.visibility = :vis_crs AND m.courseid = :crsid)' : '') . ')';
        $params['uid'] = $USER->id;
        $params['vis_site'] = LOCAL_SECUREVIDEOLIBRARY_VISIBILITY_SITEWIDE;
        if ($courseid > 1) {
            $params['vis_crs'] = LOCAL_SECUREVIDEOLIBRARY_VISIBILITY_COURSE;
            $params['crsid'] = $courseid;
        }
    }
}

if (!empty($search)) {
    $conditions[] = '(' . $DB->sql_like('m.title', ':search', false) . ' OR ' .
                          $DB->sql_like('m.description', ':searchdesc', false) . ')';
    $params['search'] = '%' . $search . '%';
    $params['searchdesc'] = '%' . $search . '%';
}

$whereclause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

$userfields = \core_user\fields::for_name()->get_sql('u');
$sql = "SELECT m.* {$userfields->selects}
          FROM {local_securevideolibrary_media} m
          JOIN {user} u ON u.id = m.uploaderid
          {$whereclause}
      ORDER BY m.id DESC";

$records = $DB->get_records_sql($sql, $params);

$hasprocessing = false;
$videos = [];

foreach ($records as $rec) {
    if (
        $rec->status === LOCAL_SECUREVIDEOLIBRARY_STATUS_QUEUED ||
        $rec->status === LOCAL_SECUREVIDEOLIBRARY_STATUS_PROCESSING
    ) {
        $hasprocessing = true;
    }

    $candelete = has_capability('local/securevideolibrary:managesitewide', context_system::instance()) ||
                 ((int)$rec->uploaderid === (int)$USER->id) ||
                 (!empty($rec->courseid) && has_capability('local/securevideolibrary:managecourse', context_course::instance($rec->courseid)));

    $mins = floor($rec->duration / 60);
    $secs = $rec->duration % 60;
    $durationformatted = sprintf('%02d:%02d', $mins, $secs);

    $statusclass = 'secondary';
    $statuslabel = get_string('status_queued', 'local_securevideolibrary');
    if ($rec->status === LOCAL_SECUREVIDEOLIBRARY_STATUS_PROCESSING) {
        $statusclass = 'info';
        $statuslabel = get_string('status_processing', 'local_securevideolibrary');
    } else if ($rec->status === LOCAL_SECUREVIDEOLIBRARY_STATUS_READY) {
        $statusclass = 'success';
        $statuslabel = get_string('status_ready', 'local_securevideolibrary');
    } else if ($rec->status === LOCAL_SECUREVIDEOLIBRARY_STATUS_FAILED) {
        $statusclass = 'danger';
        $statuslabel = get_string('status_failed', 'local_securevideolibrary');
    }

    $thumbnailurl = null;
    $playlisturl = null;
    if ($rec->status === LOCAL_SECUREVIDEOLIBRARY_STATUS_READY) {
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
        'id'                => $rec->id,
        'title'             => $rec->title,
        'description'       => $rec->description,
        'duration'          => $rec->duration,
        'durationformatted' => $durationformatted,
        'filesizeformatted' => $rec->filesize > 0 ? display_size($rec->filesize) : '-',
        'uploaderfullname'  => fullname($rec),
        'timecreated'       => userdate($rec->timecreated, get_string('strftimedatetime', 'langconfig')),
        'status'            => $rec->status,
        'statuslabel'       => $statuslabel,
        'statusclass'       => $statusclass,
        'isready'           => ($rec->status === LOCAL_SECUREVIDEOLIBRARY_STATUS_READY),
        'isprocessing'      => ($rec->status === LOCAL_SECUREVIDEOLIBRARY_STATUS_QUEUED ||
                                $rec->status === LOCAL_SECUREVIDEOLIBRARY_STATUS_PROCESSING),
        'isfailed'          => ($rec->status === LOCAL_SECUREVIDEOLIBRARY_STATUS_FAILED),
        'errormessage'      => $rec->errormessage,
        'thumbnailurl'      => $thumbnailurl,
        'playlisturl'       => $playlisturl,
        'candelete'         => $candelete,
        'deleteurl'         => (new moodle_url($pageurl, ['delete' => $rec->id, 'sesskey' => sesskey()]))->out(false),
    ];
}

$templatedata = [
    'courseid'         => $courseid,
    'canupload'        => $canupload,
    'uploadformhtml'   => $uploadform ? $uploadform->render() : '',
    'tab_my'           => ($tab === 'my'),
    'tab_course'       => ($tab === 'course'),
    'tab_all'          => ($tab === 'all'),
    'hascourse'        => ($courseid > 1),
    'myurl'            => (new moodle_url($pageurl, ['tab' => 'my']))->out(false),
    'courseurl'        => (new moodle_url($pageurl, ['tab' => 'course']))->out(false),
    'allurl'           => (new moodle_url($pageurl, ['tab' => 'all']))->out(false),
    'search'           => $search,
    'hasvideos'        => !empty($videos),
    'videos'           => $videos,
    'hasprocessing'    => $hasprocessing ? 'true' : 'false',
    'pageurl'          => $pageurl->out(false),
    'sesskey'          => sesskey(),
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_securevideolibrary/manage', $templatedata);
echo $OUTPUT->footer();
