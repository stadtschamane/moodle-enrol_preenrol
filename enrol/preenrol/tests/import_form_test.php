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
 * PHPUnit tests for the import form validation.
 *
 * @package    enrol_preenrol
 * @copyright  2026 Stadtschamane <https://github.com/stadtschamane>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace enrol_preenrol;

use advanced_testcase;
use moodle_url;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Tests \enrol_preenrol\import_form validation paths.
 *
 * @package    enrol_preenrol
 * @copyright  2026 Stadtschamane <https://github.com/stadtschamane>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class import_form_test extends advanced_testcase {

    public function test_form_built_and_parse_public(): void {
        $this->resetAfterTest();

        ob_start();
        // PHP 8.4 prints deprecation notices of the vendored mustache library
        // (lib/mustache) the first time it is loaded, which here happens inside
        // the moodleform constructor. That is environmental noise of the
        // vendor lib, not output from the form class - suppress E_DEPRECATED
        // for the construction so the assertion below stays meaningful.
        $oldlevel = error_reporting();
        error_reporting($oldlevel & ~E_DEPRECATED);
        $form = new import_form(
            new moodle_url('/enrol/preenrol/import.php', ['enrolid' => 1]));
        error_reporting($oldlevel);
        $out = ob_get_clean();
        $this->assertSame('', $out, 'form construction printed output');
        $this->assertInstanceOf(\moodleform::class, $form);
        $this->assertSame(2000, import_form::MAX_ROWS);
        $this->assertTrue(is_callable([importer::class, 'parse']));
        $tokens = importer::parse("Anna@Example.org\nben@example.org;CAROL@example.org");
        $this->assertSame(['anna@example.org', 'ben@example.org', 'carol@example.org'], $tokens);
    }

    public function test_validation_needsource(): void {
        $this->resetAfterTest();

        $form = new import_form(
            new moodle_url('/enrol/preenrol/import.php', ['enrolid' => 1]));

        // Case 1: no source at all -> needsource.
        $errors = $form->validation(['emaillist' => ''], []);
        $this->assertArrayHasKey('emaillist', $errors);
        $this->assertSame(get_string('needsource', 'enrol_preenrol'), $errors['emaillist']);

        // A source with 3 addresses -> no errors.
        $errors = $form->validation(['emaillist' => "anna@example.org\nben@example.org\ncarol@example.org"], []);
        $this->assertSame([], $errors);
    }

    public function test_validation_toomany_limit(): void {
        $this->resetAfterTest();

        $form = new import_form(
            new moodle_url('/enrol/preenrol/import.php', ['enrolid' => 1]));

        // 2001 distinct addresses -> 'toomany' error.
        $tokens = [];
        for ($i = 0; $i < import_form::MAX_ROWS + 1; $i++) {
            $tokens[] = "user{$i}@example.org";
        }
        $errors = $form->validation(['emaillist' => implode("\n", $tokens)], []);
        $this->assertArrayHasKey('emaillist', $errors);
        $this->assertSame(get_string('toomany', 'enrol_preenrol', import_form::MAX_ROWS), $errors['emaillist']);

        // Exactly 2000 -> OK.
        array_pop($tokens);
        $errors = $form->validation(['emaillist' => implode("\n", $tokens)], []);
        $this->assertSame([], $errors);
    }

    public function test_category_strings_resolve(): void {
        foreach (['pending', 'enrolled', 'alreadyenrolled', 'invalid', 'duplicate'] as $category) {
            $string = get_string('import' . $category, 'enrol_preenrol');
            $this->assertNotSame("[[import{$category}]]", $string);
        }
        foreach (['needsource', 'toomany', 'confirmimportrequired', 'importreport', 'importtimecreated',
                  'importpendingheading', 'importdeleteconfirm', 'emaillist', 'emaillistplaceholder',
                  'importattachment', 'confirmimport', 'emaillist_help', 'importattachment_help'] as $key) {
            $string = get_string($key, 'enrol_preenrol');
            $this->assertNotSame("[[{$key}]]", $string, "lang key {$key} must exist");
        }
    }

    public function test_import_form_has_field_help_buttons(): void {
        $this->resetAfterTest();

        ob_start();
        $oldlevel = error_reporting();
        error_reporting($oldlevel & ~E_DEPRECATED);
        $form = new import_form(
            new moodle_url('/enrol/preenrol/import.php', ['enrolid' => 1]));
        error_reporting($oldlevel);
        ob_end_clean();

        // The quickform is behind the protected moodleform::$_form property.
        $prop = new \ReflectionProperty(\moodleform::class, '_form');
        $prop->setAccessible(true);
        $qform = $prop->getValue($form);

        $help = (string)$qform->getElement('emaillist')->getHelpButton();
        $this->assertNotEmpty($help, 'help button html missing on emaillist');
        $this->assertStringContainsString((string)import_form::MAX_ROWS, $help,
            'the row limit must be substituted into the emaillist help text');

        $help = (string)$qform->getElement('attachment')->getHelpButton();
        $this->assertNotEmpty($help, 'help button html missing on attachment');
    }
}