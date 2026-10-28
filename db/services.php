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
 * Web service function registrations for local_securevideolibrary.
 *
 * @package    local_securevideolibrary
 * @copyright  2025 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_securevideolibrary_get_videos' => [
        'classname'   => 'local_securevideolibrary\external\get_videos',
        'methodname'  => 'execute',
        'description' => 'Get a paginated and searchable list of accessible videos from the library',
        'type'        => 'read',
        'ajax'        => true,
    ],
    'local_securevideolibrary_get_video_status' => [
        'classname'   => 'local_securevideolibrary\external\get_video_status',
        'methodname'  => 'execute',
        'description' => 'Poll the current transcoding status of a video',
        'type'        => 'read',
        'ajax'        => true,
    ],
];
