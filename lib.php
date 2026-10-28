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
 * Standard library callbacks and helper functions for local_securevideolibrary.
 *
 * @package    local_securevideolibrary
 * @copyright  2025 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/** Status: In queue awaiting transcode worker */
define('LOCAL_SECUREVIDEOLIBRARY_STATUS_QUEUED', \local_securevideolibrary\media::STATUS_QUEUED);

/** Status: Transcode in progress */
define('LOCAL_SECUREVIDEOLIBRARY_STATUS_PROCESSING', \local_securevideolibrary\media::STATUS_PROCESSING);

/** Status: Transcoding complete and HLS AES-128 stream ready */
define('LOCAL_SECUREVIDEOLIBRARY_STATUS_READY', \local_securevideolibrary\media::STATUS_READY);

/** Status: Transcoding failed */
define('LOCAL_SECUREVIDEOLIBRARY_STATUS_FAILED', \local_securevideolibrary\media::STATUS_FAILED);

/** Visibility: Only uploader */
define('LOCAL_SECUREVIDEOLIBRARY_VISIBILITY_PRIVATE', \local_securevideolibrary\media::VISIBILITY_PRIVATE);

/** Visibility: Teachers in the course */
define('LOCAL_SECUREVIDEOLIBRARY_VISIBILITY_COURSE', \local_securevideolibrary\media::VISIBILITY_COURSE);

/** Visibility: All teachers across site */
define('LOCAL_SECUREVIDEOLIBRARY_VISIBILITY_SITEWIDE', \local_securevideolibrary\media::VISIBILITY_SITEWIDE);

/**
 * Extends the course navigation to add the Secure Video Library link for teachers.
 *
 * @param navigation_node $navigation The course navigation node.
 * @param stdClass $course The course object.
 * @param context_course $coursecontext The course context.
 * @return void
 */
function local_securevideolibrary_extend_navigation_course(
    navigation_node $navigation,
    stdClass $course,
    context_course $coursecontext
): void {
    if (has_capability('local/securevideolibrary:view', $coursecontext)) {
        $url = new moodle_url('/local/securevideolibrary/manage.php', ['courseid' => $course->id]);
        $navigation->add(
            get_string('nav_securevideolibrary', 'local_securevideolibrary'),
            $url,
            navigation_node::TYPE_SETTING,
            null,
            'local_securevideolibrary',
            new pix_icon('i/video', '')
        );
    }
}

/**
 * Check if the given user has permission to access or view a specific media record.
 *
 * @param stdClass $media Database row from local_securevideolibrary_media.
 * @param int|null $userid User ID to check (null for current logged-in user).
 * @return bool True if permitted, false otherwise.
 */
function local_securevideolibrary_can_access_media(stdClass $media, ?int $userid = null): bool {
    global $USER, $DB;

    if ($userid === null) {
        $userid = (int)$USER->id;
    }

    if (empty($userid)) {
        return false;
    }

    $syscontext = context_system::instance();

    // Site managers and admins with site-wide management capability can view all media.
    if (has_capability('local/securevideolibrary:managesitewide', $syscontext, $userid)) {
        return true;
    }

    // The uploader can always view their own uploaded media.
    if ((int)$media->uploaderid === $userid) {
        return true;
    }

    // Check visibility scopes for teachers.
    if ($media->visibility === LOCAL_SECUREVIDEOLIBRARY_VISIBILITY_SITEWIDE) {
        if (has_capability('local/securevideolibrary:view', $syscontext, $userid)) {
            return true;
        }
    }

    if ($media->visibility === LOCAL_SECUREVIDEOLIBRARY_VISIBILITY_COURSE && !empty($media->courseid)) {
        $coursecontext = context_course::instance($media->courseid, IGNORE_MISSING);
        if ($coursecontext && has_capability('local/securevideolibrary:view', $coursecontext, $userid)) {
            return true;
        }
    }

    // Check if the user is a student/learner accessing through an active mod_securevideoplayer course module.
    $sql = "SELECT cm.id
              FROM {course_modules} cm
              JOIN {modules} m ON m.id = cm.module AND m.name = :modname
              JOIN {securevideoplayer} p ON p.id = cm.instance
             WHERE p.mediaid = :mediaid";

    $instances = $DB->get_records_sql($sql, ['modname' => 'securevideoplayer', 'mediaid' => $media->id]);
    foreach ($instances as $inst) {
        $modcontext = context_module::instance($inst->id, IGNORE_MISSING);
        if ($modcontext && has_capability('mod/securevideoplayer:view', $modcontext, $userid)) {
            return true;
        }
    }

    return false;
}
