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
 * Pre-enrolment plugin event observer.
 *
 * @package    enrol_preenrol
 * @category   event
 * @copyright  2026 Stadtschamane <https://github.com/stadtschamane>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace enrol_preenrol;

defined('MOODLE_INTERNAL') || die();

use context_course;

/**
 * Event observer for enrol_preenrol.
 *
 * Consumes enrol_preenrol_pending rows when accounts with matching e-mail
 * addresses are created.
 *
 * @package    enrol_preenrol
 * @copyright  2026 Stadtschamane <https://github.com/stadtschamane>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class observer {

    /**
     * Consume matching pending rows for a newly created user.
     *
     * Registered for the mandatory \core\event\user_created event in
     * db/events.php. Tries to enrol the new user into every enabled
     * preenrol instance that has a pending row for the user's e-mail
     * address and deletes consumed rows. Errors are swallowed with a
     * DEBUG_DEVELOPER notice so that account creation can never fail.
     *
     * @param \core\event\user_created $event
     */
    public static function user_created(\core\event\user_created $event): void {
        global $DB;
        $userid = $event->objectid;
        $user = $DB->get_record('user', ['id' => $userid], 'id, email', IGNORE_MISSING);
        if (!$user || empty($user->email)) { return; }
        $email = strtolower(trim($user->email));
        $pendings = $DB->get_records('enrol_preenrol_pending', ['email' => $email]);
        if (!$pendings) { return; }
        $plugin = enrol_get_plugin('preenrol');
        foreach ($pendings as $pending) {
            $instance = $DB->get_record('enrol', ['id' => $pending->enrolid, 'enrol' => 'preenrol', 'status' => ENROL_INSTANCE_ENABLED], '*', IGNORE_MISSING);
            if (!$instance) { continue; }
            if (is_enrolled(context_course::instance($instance->courseid, IGNORE_MISSING), $userid)) {
                $DB->delete_records('enrol_preenrol_pending', ['id' => $pending->id]);
                continue;
            }
            try {
                $roleid = $pending->roleid ?: $instance->roleid;
                $plugin->enrol_user($instance, $userid, (int)$roleid);
                $DB->delete_records('enrol_preenrol_pending', ['id' => $pending->id]);
            } catch (\Throwable $e) {
                debugging('enrol_preenrol observer: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }
}