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
 * Admin settings for local_securevideolibrary.
 *
 * @package    local_securevideolibrary
 * @copyright  2025 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_securevideolibrary', get_string('pluginname', 'local_securevideolibrary'));

    $settings->add(new admin_setting_heading(
        'local_securevideolibrary/general_heading',
        '',
        ''
    ));

    $settings->add(new admin_setting_configfile(
        'local_securevideolibrary/ffmpegpath',
        get_string('ffmpegpath', 'local_securevideolibrary'),
        get_string('ffmpegpath_desc', 'local_securevideolibrary'),
        '/usr/bin/ffmpeg'
    ));

    $settings->add(new admin_setting_configtext(
        'local_securevideolibrary/segmentduration',
        get_string('segmentduration', 'local_securevideolibrary'),
        get_string('segmentduration_desc', 'local_securevideolibrary'),
        '6',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configselect(
        'local_securevideolibrary/compressionprofile',
        get_string('compressionprofile', 'local_securevideolibrary'),
        get_string('compressionprofile_desc', 'local_securevideolibrary'),
        'balanced',
        [
            'datasaver' => get_string('profile_datasaver', 'local_securevideolibrary'),
            'balanced'  => get_string('profile_balanced', 'local_securevideolibrary'),
            'high'      => get_string('profile_high', 'local_securevideolibrary'),
        ]
    ));

    $settings->add(new admin_setting_configselect(
        'local_securevideolibrary/defaultbitrateprofile',
        get_string('defaultbitrateprofile', 'local_securevideolibrary'),
        get_string('defaultbitrateprofile_desc', 'local_securevideolibrary'),
        'single',
        [
            'single' => get_string('bitrate_single', 'local_securevideolibrary'),
            'multi'  => get_string('bitrate_multi', 'local_securevideolibrary'),
        ]
    ));

    $ADMIN->add('localplugins', $settings);

    // Link directly to the Video Library Management interface.
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_securevideolibrary_manage',
        get_string('managevideos', 'local_securevideolibrary'),
        new moodle_url('/local/securevideolibrary/manage.php')
    ));
}
