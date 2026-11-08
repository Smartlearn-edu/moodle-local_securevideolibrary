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
use local_securevideolibrary\media;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Form to import a pre-transcoded and encrypted video package (.zip).
 *
 * @package    local_securevideolibrary
 * @copyright  2025 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class import_package_form extends moodleform {
    /**
     * Form definition.
     *
     * @return void
     */
    protected function definition(): void {
        $mform = $this->_form;
        $courseid = $this->_customdata['courseid'] ?? 0;

        $mform->addElement('header', 'general', get_string('importpackage', 'local_securevideolibrary'));

        // Optional title override.
        $mform->addElement(
            'text',
            'title',
            get_string('title', 'local_securevideolibrary'),
            ['size' => '50', 'placeholder' => get_string('title_placeholder_import', 'local_securevideolibrary')]
        );
        $mform->setType('title', PARAM_TEXT);
        $mform->addHelpButton('title', 'title_placeholder_import', 'local_securevideolibrary');

        // Optional description.
        $mform->addElement('textarea', 'description', get_string('description', 'local_securevideolibrary'), 'rows="3" cols="50"');
        $mform->setType('description', PARAM_TEXT);

        // Visibility / Sharing scope.
        $visibilityoptions = [
            media::VISIBILITY_PRIVATE => get_string('visibility_private', 'local_securevideolibrary'),
        ];

        if ($courseid > 1) {
            $visibilityoptions[media::VISIBILITY_COURSE] =
                get_string('visibility_course', 'local_securevideolibrary');
        }

        if (has_capability('local/securevideolibrary:managesitewide', \context_system::instance())) {
            $visibilityoptions[media::VISIBILITY_SITEWIDE] =
                get_string('visibility_sitewide', 'local_securevideolibrary');
        }

        $mform->addElement('select', 'visibility', get_string('visibility', 'local_securevideolibrary'), $visibilityoptions);
        $mform->addHelpButton('visibility', 'visibility', 'local_securevideolibrary');
        $mform->setDefault('visibility', $courseid > 1 ? media::VISIBILITY_COURSE : media::VISIBILITY_PRIVATE);

        // Package zip filepicker.
        $filepickeroptions = [
            'subdirs'        => 0,
            'maxbytes'       => 0, // Site maximum.
            'maxfiles'       => 1,
            'accepted_types' => ['.zip'],
        ];
        $mform->addElement(
            'filepicker',
            'packagefile',
            get_string('packagefile', 'local_securevideolibrary'),
            null,
            $filepickeroptions
        );
        $mform->addHelpButton('packagefile', 'packagefile', 'local_securevideolibrary');
        $mform->addRule('packagefile', null, 'required', null, 'client');

        $this->add_action_buttons(true, get_string('importpackage', 'local_securevideolibrary'));
    }
}
