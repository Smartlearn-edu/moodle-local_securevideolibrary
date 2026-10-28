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

namespace local_securevideolibrary;

/**
 * Media constants and status definitions.
 *
 * @package    local_securevideolibrary
 * @copyright  2025 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class media {
    /** Status: In queue awaiting transcode worker */
    public const STATUS_QUEUED = 'queued';

    /** Status: Transcode in progress */
    public const STATUS_PROCESSING = 'processing';

    /** Status: Transcoding complete and HLS AES-128 stream ready */
    public const STATUS_READY = 'ready';

    /** Status: Transcoding failed */
    public const STATUS_FAILED = 'failed';

    /** Visibility: Only uploader */
    public const VISIBILITY_PRIVATE = 'private';

    /** Visibility: Teachers in the course */
    public const VISIBILITY_COURSE = 'course';

    /** Visibility: All teachers across site */
    public const VISIBILITY_SITEWIDE = 'sitewide';
}
