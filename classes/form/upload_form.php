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

namespace local_securevideolibrary\form;

use moodleform;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Upload form for adding videos to the secure video library.
 *
 * @package    local_securevideolibrary
 * @copyright  2025 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class upload_form extends moodleform {
    /**
     * Form definition.
     *
     * @return void
     */
    protected function definition(): void {
        $mform = $this->_form;
        $courseid = $this->_customdata['courseid'] ?? 0;
        $context = $this->_customdata['context'];

        $mform->addElement('header', 'general', get_string('uploadvideo', 'local_securevideolibrary'));

        // Video title.
        $mform->addElement('text', 'title', get_string('title', 'local_securevideolibrary'), ['size' => '50']);
        $mform->setType('title', PARAM_TEXT);
        $mform->addRule('title', null, 'required', null, 'client');

        // Optional description.
        $mform->addElement('textarea', 'description', get_string('description', 'local_securevideolibrary'), 'rows="3" cols="50"');
        $mform->setType('description', PARAM_TEXT);

        // Visibility / Sharing scope.
        $visibilityoptions = [
            LOCAL_SECUREVIDEOLIBRARY_VISIBILITY_PRIVATE => get_string('visibility_private', 'local_securevideolibrary'),
        ];

        if ($courseid > 1) {
            $visibilityoptions[LOCAL_SECUREVIDEOLIBRARY_VISIBILITY_COURSE] =
                get_string('visibility_course', 'local_securevideolibrary');
        }

        if (has_capability('local/securevideolibrary:managesitewide', \context_system::instance())) {
            $visibilityoptions[LOCAL_SECUREVIDEOLIBRARY_VISIBILITY_SITEWIDE] =
                get_string('visibility_sitewide', 'local_securevideolibrary');
        }

        $mform->addElement('select', 'visibility', get_string('visibility', 'local_securevideolibrary'), $visibilityoptions);
        $mform->addHelpButton('visibility', 'visibility', 'local_securevideolibrary');
        $mform->setDefault('visibility', $courseid > 1 ? LOCAL_SECUREVIDEOLIBRARY_VISIBILITY_COURSE : LOCAL_SECUREVIDEOLIBRARY_VISIBILITY_PRIVATE);

        // Bitrate Profile.
        $defaultprofile = get_config('local_securevideolibrary', 'defaultbitrateprofile') ?: 'single';
        $bitrateoptions = [
            'single' => get_string('bitrate_single', 'local_securevideolibrary'),
            'multi'  => get_string('bitrate_multi', 'local_securevideolibrary'),
        ];
        $mform->addElement('select', 'bitrateprofile', get_string('bitrateprofile', 'local_securevideolibrary'), $bitrateoptions);
        $mform->addHelpButton('bitrateprofile', 'bitrateprofile', 'local_securevideolibrary');
        $mform->setDefault('bitrateprofile', $defaultprofile);

        // Filepicker for video.
        $filepickeroptions = [
            'subdirs'        => 0,
            'maxbytes'       => 0, // Site maximum.
            'maxfiles'       => 1,
            'accepted_types' => ['.mp4', '.mov', '.mkv', '.webm', '.m4v'],
        ];
        $mform->addElement('filepicker', 'videofile', get_string('videofile', 'local_securevideolibrary'), null, $filepickeroptions);
        $mform->addHelpButton('videofile', 'videofile', 'local_securevideolibrary');
        $mform->addRule('videofile', null, 'required', null, 'client');

        // Hidden course ID.
        $mform->addElement('hidden', 'courseid', $courseid);
        $mform->setType('courseid', PARAM_INT);

        $this->add_action_buttons(true, get_string('upload', 'local_securevideolibrary'));
    }
}
