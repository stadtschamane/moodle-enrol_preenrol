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
 * PHPUnit tests for the enrol_preenrol user_created observer.
 *
 * @package    enrol_preenrol
 * @copyright  2026 Stadtschamane <https://github.com/stadtschamane>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Tests the user_created observer: consuming pending rows on account creation.
 *
 * @package    enrol_preenrol
 * @copyright  2026 Stadtschamane <https://github.com/stadtschamane>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class observer_test extends advanced_testcase {

    /**
     * Case a): A pending row is consumed when a user with a matching e-mail
     * address is created - the user is enrolled and the pending row is gone.
     */
    public function test_user_created_enrols_and_consumes_pending_row(): void {
        global $DB;

        $this->resetAfterTest();

        $plugin = enrol_get_plugin('preenrol');
        $this->assertNotNull($plugin, 'enrol_preenrol plugin must be installed');

        $course = self::getDataGenerator()->create_course();
        $enrolid = $plugin->add_instance($course, ['name' => 'Pre-enrol observer test']);
        $this->assertNotEmpty($enrolid);

        // A pending pre-enrolment waiting for this e-mail address.
        $DB->insert_record('enrol_preenrol_pending', [
            'enrolid' => $enrolid,
            'courseid' => $course->id,
            'email' => 'pending@example.com',
            'roleid' => null,
            'timecreated' => time(),
        ]);

        // Creating the user fires \core\event\user_created itself.
        $user = self::getDataGenerator()->create_user(['email' => 'pending@example.com']);

        $this->assertTrue(is_enrolled(context_course::instance($course->id), $user->id));
        $this->assertSame(1, $DB->count_records('user_enrolments',
            ['userid' => $user->id, 'enrolid' => $enrolid]));
        $this->assertSame(0, $DB->count_records('enrol_preenrol_pending', ['enrolid' => $enrolid]));
    }

    /**
     * Case b): Re-firing user_created for an already enrolled user is
     * idempotent - no exception, no debugging output, no second enrolment
     * and no pending row.
     */
    public function test_user_created_event_refire_is_idempotent(): void {
        global $DB;

        $this->resetAfterTest();

        $plugin = enrol_get_plugin('preenrol');
        $course = self::getDataGenerator()->create_course();
        $enrolid = $plugin->add_instance($course, ['name' => 'Pre-enrol observer test']);

        $DB->insert_record('enrol_preenrol_pending', [
            'enrolid' => $enrolid,
            'courseid' => $course->id,
            'email' => 'pending@example.com',
            'roleid' => null,
            'timecreated' => time(),
        ]);

        // First event arrives via account creation and enrols the user.
        $user = self::getDataGenerator()->create_user(['email' => 'pending@example.com']);
        $this->assertSame(1, $DB->count_records('user_enrolments',
            ['userid' => $user->id, 'enrolid' => $enrolid]));
        $this->assertSame(0, $DB->count_records('enrol_preenrol_pending', ['enrolid' => $enrolid]));

        // Now fire the same event again by hand.
        \core\event\user_created::create_from_userid($user->id)->trigger();

        $this->assertSame(1, $DB->count_records('user_enrolments',
            ['userid' => $user->id, 'enrolid' => $enrolid]));
        $this->assertSame(0, $DB->count_records('enrol_preenrol_pending', ['enrolid' => $enrolid]));
        $this->assertDebuggingNotCalled();
    }

    /**
     * Case c): With the enrol instance disabled, a matching pending row stays
     * untouched and the new user is not enrolled.
     */
    public function test_user_created_leaves_pending_row_when_instance_disabled(): void {
        global $DB;

        $this->resetAfterTest();

        $plugin = enrol_get_plugin('preenrol');
        $course = self::getDataGenerator()->create_course();
        $enrolid = $plugin->add_instance($course, ['name' => 'Pre-enrol observer test']);

        $instance = $DB->get_record('enrol', ['id' => $enrolid], '*', MUST_EXIST);
        $instance->status = ENROL_INSTANCE_DISABLED;
        $DB->update_record('enrol', $instance);

        $DB->insert_record('enrol_preenrol_pending', [
            'enrolid' => $enrolid,
            'courseid' => $course->id,
            'email' => 'disabled@example.com',
            'roleid' => null,
            'timecreated' => time(),
        ]);

        $user = self::getDataGenerator()->create_user(['email' => 'disabled@example.com']);

        $this->assertFalse(is_enrolled(context_course::instance($course->id), $user->id));
        $this->assertSame(0, $DB->count_records('user_enrolments', ['userid' => $user->id]));
        // The pending row is kept for a later re-enable.
        $this->assertSame(1, $DB->count_records('enrol_preenrol_pending', ['enrolid' => $enrolid]));
    }
}