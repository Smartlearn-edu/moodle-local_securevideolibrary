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

namespace local_securevideolibrary\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\writer;
use core_privacy\local\request\transform;

/**
 * Privacy API implementation for local_securevideolibrary.
 *
 * @package    local_securevideolibrary
 * @copyright  2025 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements \core_privacy\local\metadata\provider, \core_privacy\local\request\core_userlist_provider, \core_privacy\local\request\plugin\provider {
    /**
     * Provide metadata about the information stored by this plugin.
     *
     * @param collection $collection The initialised collection to add items to.
     * @return collection A listing of user data stored through this plugin.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'local_securevideolibrary_media',
            [
                'title'        => 'privacy:metadata:local_securevideolibrary_media:title',
                'description'  => 'privacy:metadata:local_securevideolibrary_media:description',
                'uploaderid'   => 'privacy:metadata:local_securevideolibrary_media:uploaderid',
                'courseid'     => 'privacy:metadata:local_securevideolibrary_media:courseid',
                'timecreated'  => 'privacy:metadata:local_securevideolibrary_media:timecreated',
                'timemodified' => 'privacy:metadata:local_securevideolibrary_media:timemodified',
            ],
            'privacy:metadata:local_securevideolibrary_media'
        );

        return $collection;
    }

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param int $userid The user to search.
     * @return contextlist $contextlist The contextlist containing the list of contexts.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $sql = "SELECT c.id
                  FROM {context} c
                  JOIN {local_securevideolibrary_media} m ON m.contextid = c.id
                 WHERE m.uploaderid = :userid";

        $contextlist->add_from_sql($sql, ['userid' => $userid]);

        return $contextlist;
    }

    /**
     * Get the list of users within a specific context.
     *
     * @param userlist $userlist The userlist to add to.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();

        $sql = "SELECT m.uploaderid
                  FROM {local_securevideolibrary_media} m
                 WHERE m.contextid = :contextid";

        $userlist->add_from_sql('uploaderid', $sql, ['contextid' => $context->id]);
    }

    /**
     * Export all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to export information for.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $user = $contextlist->get_user();

        foreach ($contextlist->get_contexts() as $context) {
            $records = $DB->get_records('local_securevideolibrary_media', [
                'contextid'  => $context->id,
                'uploaderid' => $user->id,
            ]);

            foreach ($records as $record) {
                $data = (object)[
                    'title'        => $record->title,
                    'description'  => $record->description,
                    'duration'     => $record->duration,
                    'visibility'   => $record->visibility,
                    'status'       => $record->status,
                    'timecreated'  => transform::datetime($record->timecreated),
                    'timemodified' => transform::datetime($record->timemodified),
                ];

                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_securevideolibrary'), $record->id],
                    $data
                );
            }
        }
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param \context $context The specific context to delete data for.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        $records = $DB->get_records('local_securevideolibrary_media', ['contextid' => $context->id]);
        foreach ($records as $record) {
            \local_securevideolibrary\storage::delete_media_files((int)$record->id);
            $DB->delete_records('local_securevideolibrary_media', ['id' => $record->id]);
        }
    }

    /**
     * Delete all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts and user information to delete information for.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $user = $contextlist->get_user();

        foreach ($contextlist->get_contexts() as $context) {
            $records = $DB->get_records('local_securevideolibrary_media', [
                'contextid'  => $context->id,
                'uploaderid' => $user->id,
            ]);

            foreach ($records as $record) {
                \local_securevideolibrary\storage::delete_media_files((int)$record->id);
                $DB->delete_records('local_securevideolibrary_media', ['id' => $record->id]);
            }
        }
    }

    /**
     * Delete multiple users within a single context.
     *
     * @param approved_userlist $userlist The approved context and user information to delete information for.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();
        $userids = $userlist->get_userids();

        if (empty($userids)) {
            return;
        }

        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['contextid'] = $context->id;

        $records = $DB->get_records_select(
            'local_securevideolibrary_media',
            "contextid = :contextid AND uploaderid $insql",
            $params
        );

        foreach ($records as $record) {
            \local_securevideolibrary\storage::delete_media_files((int)$record->id);
            $DB->delete_records('local_securevideolibrary_media', ['id' => $record->id]);
        }
    }
}
