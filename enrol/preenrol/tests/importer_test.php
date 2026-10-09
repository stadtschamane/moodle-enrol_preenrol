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
 * PHPUnit tests for the enrol_preenrol importer.
 *
 * @package    enrol_preenrol
 * @copyright  2026 Stadtschamane <https://github.com/stadtschamane>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Tests the import_text() report categories and enrolment behaviour.
 *
 * @package    enrol_preenrol
 * @copyright  2026 Stadtschamane <https://github.com/stadtschamane>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class importer_test extends advanced_testcase {

    /**
     * Full scenario from the import specification.
     *
     * Import 1: two pending rows, one duplicate (same address with different
     * case) and one invalid token, nobody is enrolled.
     * Note: newuser@example.org is a syntactically valid address (Moodle's
     * own validator - clean_param(PARAM_EMAIL) via validate_email() /
     * PHPMailer::validateAddress() - has no TLD restrictions, verified
     * against Moodle 4.5 on 2026-10-09), so it is accepted as pending and
     * not counted as invalid.
     * Import 2: an address that already belongs to a real user enrols that user.
     * Import 3 (re-import of both addresses): the address without a user but
     * with an existing pending row is a duplicate, the already enrolled user
     * is reported as alreadyenrolled, nobody is enrolled again.
     */
    public function test_import_text_report_and_enrolment(): void {
        global $DB;

        $this->resetAfterTest();

        $plugin = enrol_get_plugin('preenrol');
        $this->assertNotNull($plugin, 'enrol_preenrol plugin must be installed');

        $course = self::getDataGenerator()->create_course();
        $enrolid = $plugin->add_instance($course, ['name' => 'Pre-enrol test']);
        $this->assertNotEmpty($enrolid);

        // An address that already belongs to a real Moodle account.
        $existing = self::getDataGenerator()->create_user(['email' => 'existing@example.com']);

        // Import 1: mixed list with a case-insensitive duplicate, an empty
        // line and an invalid token.
        $summary = \enrol_preenrol\importer::import_text($enrolid,
            "Neu@Example.com\nneu@example.com\n\nnope\nnewuser@example.org");

        $this->assertSame(2, $summary['pending']);
        $this->assertSame(0, $summary['enrolled']);
        $this->assertSame(0, $summary['alreadyenrolled']);
        $this->assertSame(1, $summary['invalid']);
        $this->assertSame(1, $summary['duplicate']);

        // Two pending DB rows, both normalised to lowercase.
        $rows = $DB->get_records('enrol_preenrol_pending', ['enrolid' => $enrolid], 'id');
        $this->assertCount(2, $rows);
        $this->assertSame(['neu@example.com', 'newuser@example.org'],
            array_values(array_column($rows, 'email')));
        $row = reset($rows);
        $this->assertSame($enrolid, (int)$row->enrolid);
        $this->assertSame((int)$course->id, (int)$row->courseid);
        $this->assertNull($row->roleid);
        $this->assertGreaterThan(0, (int)$row->timecreated);
        $this->assertSame(4, count($summary['lines']));
        $this->assertSame('pending', $summary['lines'][0]['category']);
        $this->assertSame('duplicate', $summary['lines'][1]['category']);
        $this->assertSame('invalid', $summary['lines'][2]['category']);
        $this->assertSame('pending', $summary['lines'][3]['category']);

        // Import 2: the address of an existing (not yet enrolled) user.
        $summary = \enrol_preenrol\importer::import_text($enrolid, 'existing@example.com');

        $this->assertSame(1, $summary['enrolled']);
        $this->assertSame(0, $summary['pending']);
        $this->assertTrue(is_enrolled(context_course::instance($course->id), $existing));
        $this->assertSame(1, $DB->count_records('user_enrolments',
            ['userid' => $existing->id, 'enrolid' => $enrolid]));

        // Import 3: re-import of both addresses.
        $summary = \enrol_preenrol\importer::import_text($enrolid,
            "Neu@Example.com\nexisting@example.com");

        $this->assertSame(1, $summary['duplicate']);
        $this->assertSame(1, $summary['alreadyenrolled']);
        $this->assertSame(0, $summary['enrolled']);
        $this->assertSame(0, $summary['pending']);

        // Still exactly one enrolment (idempotent re-import).
        $this->assertSame(1, $DB->count_records('user_enrolments',
            ['userid' => $existing->id, 'enrolid' => $enrolid]));
        // The pending rows are untouched by the re-import.
        $this->assertSame(2, $DB->count_records('enrol_preenrol_pending', ['enrolid' => $enrolid]));
    }

    /**
     * Separator handling: newline, semicolon, comma and tab all split;
     * whitespace around addresses is trimmed, case is normalised.
     */
    public function test_import_text_separators_and_normalisation(): void {
        global $DB;

        $this->resetAfterTest();

        $plugin = enrol_get_plugin('preenrol');
        $course = self::getDataGenerator()->create_course();
        $enrolid = $plugin->add_instance($course, []);

        $summary = \enrol_preenrol\importer::import_text($enrolid,
            " A@Example.com ; b@example.com,c@example.com\td@example.com ");

        $this->assertSame(4, $summary['pending']);
        $this->assertSame(0, $summary['invalid']);
        $this->assertSame(0, $summary['duplicate']);

        $emails = array_column($DB->get_records('enrol_preenrol_pending', ['enrolid' => $enrolid], 'id', 'email'), 'email');
        $this->assertEquals(['a@example.com', 'b@example.com', 'c@example.com', 'd@example.com'],
            array_values($emails));
    }
}