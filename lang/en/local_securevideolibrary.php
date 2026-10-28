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
 * Language strings for local_securevideolibrary.
 *
 * @package    local_securevideolibrary
 * @copyright  2025 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['actions'] = 'Actions';
$string['allvideos'] = 'All videos';
$string['bitrateprofile'] = 'Transcoding profile';
$string['bitrateprofile_help'] = 'Single bitrate transcode provides fast processing for high quality playback. Multi-bitrate creates an adaptive ladder (360p, 720p, 1080p) to support various network speeds.';
$string['bitrate_multi'] = 'Multi-bitrate (Adaptive 360p / 720p / 1080p)';
$string['bitrate_single'] = 'Single bitrate (Optimized standard quality)';
$string['cannotaccessvideo'] = 'You do not have permission to access or view this video.';
$string['confirmdelete'] = 'Are you sure you want to delete the video "{$a}"? All encrypted streaming segments and files will be permanently deleted from the server.';
$string['coursevideos'] = 'Course videos';
$string['defaultbitrateprofile'] = 'Default transcoding profile';
$string['defaultbitrateprofile_desc'] = 'Select the default transcoding profile applied when uploading new videos.';
$string['delete'] = 'Delete';
$string['deletefailed'] = 'Could not delete the selected video.';
$string['deletesuccess'] = 'Video deleted successfully.';
$string['description'] = 'Description';
$string['duration'] = 'Duration';
$string['err_ffmpegnotfound'] = 'FFmpeg executable was not found at "{$a}". Please check plugin settings.';
$string['err_filenotfound'] = 'The requested video file or streaming segment was not found.';
$string['err_invalidmedia'] = 'Invalid or non-existent video media ID.';
$string['err_transcodefailed'] = 'Transcoding failed: {$a}';
$string['err_uploadempty'] = 'No video file was uploaded or file is empty.';
$string['ffmpegpath'] = 'FFmpeg binary path';
$string['ffmpegpath_desc'] = 'The absolute path to the ffmpeg executable on your server (e.g. /usr/bin/ffmpeg).';
$string['filesize'] = 'Size';
$string['managevideos'] = 'Manage video library';
$string['myvideos'] = 'My uploads';
$string['nav_securevideolibrary'] = 'Secure Video Library';
$string['novideos'] = 'No videos found in this library scope.';
$string['pluginname'] = 'Secure Video Library';
$string['preview'] = 'Preview';
$string['previewvideo'] = 'Preview video: {$a}';
$string['privacy:metadata:local_securevideolibrary_media'] = 'Stores uploaded encrypted video records and encryption credentials.';
$string['privacy:metadata:local_securevideolibrary_media:courseid'] = 'The course ID context where the video was uploaded.';
$string['privacy:metadata:local_securevideolibrary_media:description'] = 'Description of the video.';
$string['privacy:metadata:local_securevideolibrary_media:timecreated'] = 'Timestamp when the video was uploaded.';
$string['privacy:metadata:local_securevideolibrary_media:timemodified'] = 'Timestamp when the video record was last modified.';
$string['privacy:metadata:local_securevideolibrary_media:title'] = 'The title of the video.';
$string['privacy:metadata:local_securevideolibrary_media:uploaderid'] = 'The ID of the user who uploaded the video.';
$string['searchvideos'] = 'Search videos...';
$string['securevideolibrary:managecourse'] = 'Manage videos for enrolled courses';
$string['securevideolibrary:managesitewide'] = 'Manage all videos across the entire site';
$string['securevideolibrary:upload'] = 'Upload new videos to the library';
$string['securevideolibrary:view'] = 'View videos in the secure video library';
$string['segmentduration'] = 'HLS segment duration';
$string['segmentduration_desc'] = 'Duration of each encrypted TS segment in seconds (default: 6 seconds).';
$string['status'] = 'Status';
$string['status_failed'] = 'Failed';
$string['status_processing'] = 'Transcoding';
$string['status_queued'] = 'Queued';
$string['status_ready'] = 'Ready';
$string['task_transcode'] = 'Transcode video to AES-128 HLS';
$string['title'] = 'Title';
$string['transcodingprogress'] = 'Transcoding in background... This page will update automatically.';
$string['upload'] = 'Upload video';
$string['uploadanother'] = 'Upload another video';
$string['uploadedby'] = 'Uploaded by';
$string['uploadsuccess'] = 'Video uploaded successfully. Transcoding task has been queued.';
$string['uploadvideo'] = 'Upload video to library';
$string['videofile'] = 'Video file';
$string['videofile_help'] = 'Upload MP4, MOV, MKV, or WebM video file. Once transcoding completes, the raw file will be purged from the server to save disk space.';
$string['visibility'] = 'Visibility & Sharing';
$string['visibility_course'] = 'Course (Teachers in this course)';
$string['visibility_help'] = 'Controls who can use and select this video. Private is visible only to you. Course makes it available to teachers in this course. Site-wide makes it available across all courses.';
$string['visibility_private'] = 'Private (Only me)';
$string['visibility_sitewide'] = 'Site-wide (All teachers on site)';
