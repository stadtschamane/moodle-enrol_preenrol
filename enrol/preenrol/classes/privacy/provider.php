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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Privacy Subsystem implementation for enrol_preenrol.
 *
 * @package    enrol_preenrol
 * @category   privacy
 * @copyright  2026 Stadtschamane <https://github.com/stadtschamane>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace enrol_preenrol\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy Subsystem implementation for enrol_preenrol.
 *
 * Pending pre-enrolments are identified by e-mail address only and are not
 * linked to a Moodle user id. Each row belongs to the course context. Within
 * user data requests a row is reported for the user whose account e-mail
 * address matches the pending address case-insensitively.
 *
 * @package    enrol_preenrol
 * @copyright  2026 Stadtschamane <https://github.com/stadtschamane>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
        // This plugin stores pending pre-enrolment e-mail addresses.
        \core_privacy\local\metadata\provider,

        // This plugin contains user's pre-enrolments (by e-mail address).
        \core_privacy\local\request\plugin\provider,

        // This plugin is capable of determining which users have data within it.
        \core_privacy\local\request\core_userlist_provider {

    /**
     * Returns metadata about this system.
     *
     * @param collection $collection The initialised collection to add items to.
     * @return collection A listing of user data stored through this system.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'enrol_preenrol_pending',
            [
                'enrolid' => 'privacy:metadata:enrol_preenrol:pending:enrolid',
                'courseid' => 'privacy:metadata:enrol_preenrol:pending:courseid',
                'email' => 'privacy:metadata:enrol_preenrol:pending:email',
                'roleid' => 'privacy:metadata:enrol_preenrol:pending:roleid',
                'timecreated' => 'privacy:metadata:enrol_preenrol:pending:timecreated',
            ],
            'privacy:metadata:enrol_preenrol:pending'
        );

        return $collection;
    }

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param int $userid The user to search.
     * @return contextlist $contextlist The contextlist containing the list of contexts used in this plugin.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        // The p.email column stores addresses normalised to lowercase, so there
        // is no need to wrap it in LOWER() in the following query.
        $sql = "SELECT ctx.id
                  FROM {enrol_preenrol_pending} p
                  JOIN {user} u ON LOWER(u.email) = p.email
                  JOIN {context} ctx ON ctx.contextlevel = :contextcourse AND ctx.instanceid = p.courseid
                 WHERE u.id = :userid";
        $params = [
            'contextcourse' => CONTEXT_COURSE,
            'userid' => $userid,
        ];

        $contextlist->add_from_sql($sql, $params);

        return $contextlist;
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param userlist $userlist The userlist containing the list of users who have data in this context/plugin combination.
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();

        if (!$context instanceof \context_course) {
            return;
        }

        // The p.email column stores addresses normalised to lowercase, so there
        // is no need to wrap it in LOWER() in the following query.
        $sql = "SELECT u.id
                  FROM {enrol_preenrol_pending} p
                  JOIN {user} u ON LOWER(u.email) = p.email
                 WHERE p.courseid = :courseid";
        $params = ['courseid' => $context->instanceid];

        $userlist->add_from_sql('id', $sql, $params);
    }

    /**
     * Export all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to export information for.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $user = $contextlist->get_user();

        list($contextsql, $contextparams) = $DB->get_in_or_equal($contextlist->get_contextids(), SQL_PARAMS_NAMED);

        // The p.email column stores addresses normalised to lowercase, so there
        // is no need to wrap it in LOWER() in the following query.
        $sql = "SELECT p.email, p.roleid, p.timecreated, p.courseid, r.shortname
                  FROM {enrol_preenrol_pending} p
                  JOIN {user} u ON LOWER(u.email) = p.email
                  JOIN {context} ctx ON ctx.contextlevel = :contextcourse AND ctx.instanceid = p.courseid
             LEFT JOIN {role} r ON r.id = p.roleid
                 WHERE u.id = :userid AND ctx.id {$contextsql}";
        $params = [
            'contextcourse' => CONTEXT_COURSE,
            'userid' => $user->id,
        ] + $contextparams;

        $subcontext = \core_enrol\privacy\provider::get_subcontext([get_string('pluginname', 'enrol_preenrol')]);

        $pending = [];
        $recordset = $DB->get_recordset_sql($sql, $params);
        foreach ($recordset as $record) {
            $record->timecreated = \core_privacy\local\request\transform::datetime($record->timecreated);
            $pending[$record->courseid][] = $record;
        }
        $recordset->close();

        foreach ($pending as $courseid => $entries) {
            $data = (object) [
                'pendingpreenrolments' => $entries,
            ];
            writer::with_context(\context_course::instance($courseid))->export_data($subcontext, $data);
        }
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param \context $context The specific context to delete data for.
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;

        if (!$context instanceof \context_course) {
            return;
        }

        $DB->delete_records('enrol_preenrol_pending', ['courseid' => $context->instanceid]);
    }

    /**
     * Delete all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts and user information to delete information for.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $contexts = $contextlist->get_contexts();
        $courseids = [];
        foreach ($contexts as $context) {
            if ($context instanceof \context_course) {
                $courseids[] = $context->instanceid;
            }
        }
        if (empty($courseids)) {
            return;
        }

        list($insql, $inparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);

        // Pending rows are not linked to a user id; they are identified by the
        // account e-mail address, which is stored lowercase.
        $select = "courseid {$insql} AND email = :email";
        $params = $inparams + ['email' => \core_text::strtolower($contextlist->get_user()->email)];
        $DB->delete_records_select('enrol_preenrol_pending', $select, $params);
    }

    /**
     * Delete multiple users within a single context.
     *
     * @param approved_userlist $userlist The approved context and user information to delete information for.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();

        if (!$context instanceof \context_course) {
            return;
        }

        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }

        list($usersql, $userparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);

        $select = "courseid = :courseid AND email IN (SELECT LOWER(email) FROM {user} WHERE id {$usersql})";
        $params = ['courseid' => $context->instanceid] + $userparams;
        $DB->delete_records_select('enrol_preenrol_pending', $select, $params);
    }
}
