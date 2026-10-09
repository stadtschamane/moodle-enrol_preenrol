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
 * PHPUnit tests for the enrol_preenrol plugin API surface.
 *
 * @package    enrol_preenrol
 * @copyright  2026 Stadtschamane <https://github.com/stadtschamane>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Tests the unenrol/manage entry points: allow_* overrides and capability definitions.
 *
 * @package    enrol_preenrol
 * @copyright  2026 Stadtschamane <https://github.com/stadtschamane>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class api_test extends advanced_testcase {

    /**
     * allow_unenrol()/allow_manage() return true, so the core participants page
     * offers the unenrol/edit icons. Capability enforcement happens in the core.
     */
    public function test_allow_unenrol_and_allow_manage_return_true(): void {
        global $DB;

        $this->resetAfterTest();

        $plugin = enrol_get_plugin('preenrol');
        $this->assertNotNull($plugin, 'enrol_preenrol plugin must be installed');

        $course = self::getDataGenerator()->create_course();
        $enrolid = $plugin->add_instance($course, ['name' => 'Pre-enrol api test']);
        $this->assertNotEmpty($enrolid);

        $instance = $DB->get_record('enrol', array('id' => $enrolid), '*', MUST_EXIST);
        $this->assertSame('preenrol', $instance->enrol);

        $this->assertSame(true, $plugin->allow_unenrol($instance));
        $this->assertSame(true, $plugin->allow_manage($instance));
    }

    /**
     * The unenrol/manage capabilities are defined by db/access.php with the
     * expected type and context level.
     */
    public function test_unenrol_and_manage_capabilities_exist(): void {
        $this->resetAfterTest();

        foreach (['enrol/preenrol:unenrol', 'enrol/preenrol:manage'] as $capname) {
            $info = get_capability_info($capname);
            $this->assertNotNull($info, "Capability $capname must be defined");
            $this->assertSame('write', $info->captype);
            // Moodle DML returns scalars from the DB as strings; compare numerically.
            $this->assertSame(CONTEXT_COURSE, (int)$info->contextlevel);
        }
    }
}
