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
 * Import form for the pre-enrolment plugin.
 *
 * Collects the source of the import (pasted address list and/or uploaded
 * CSV file) and makes sure at least one source is filled and that the
 * import stays below the row limit. The actual import is done by
 * {@see \enrol_preenrol\importer::import_text()}.
 *
 * @package    enrol_preenrol
 * @copyright  2026 Stadtschamane <https://github.com/stadtschamane>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace enrol_preenrol;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir.'/formslib.php');

use moodleform;

/**
 * Bulk import form (address list + optional CSV attachment).
 *
 * @package    enrol_preenrol
 * @copyright  2026 Stadtschamane <https://github.com/stadtschamane>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class import_form extends moodleform {

    /**
     * Maximum number of addresses accepted in a single import.
     *
     * Used both by validation() and as the {$a} parameter of the 'toomany'
     * error string.
     */
    const MAX_ROWS = 2000;

    /**
     * Form definition.
     *
     * @return void
     */
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('textarea', 'emaillist', get_string('emaillist', 'enrol_preenrol'), [
            'placeholder' => get_string('emaillistplaceholder', 'enrol_preenrol'),
            'rows' => 8,
            'cols' => 60,
        ]);
        $mform->setType('emaillist', PARAM_TEXT);
        $mform->addHelpButton('emaillist', 'emaillist', 'enrol_preenrol', '', true,
            import_form::MAX_ROWS);

        $mform->addElement('filepicker', 'attachment', get_string('importattachment', 'enrol_preenrol'), null, [
            'accepted_types' => ['.csv', '.txt'],
        ]);
        $mform->setType('attachment', PARAM_INT);
        $mform->addHelpButton('attachment', 'importattachment', 'enrol_preenrol', '', true,
            import_form::MAX_ROWS);

        $this->add_action_buttons(true, get_string('import', 'enrol_preenrol'));
    }

    /**
     * Extra validation: one source minimum and row limit.
     *
     * @param array $data array of ("fieldname" => value) of submitted data
     * @param array $files array of uploaded files "element_name" => tmp_file_path
     * @return array of "element_name" => "error_description", empty when OK
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        $text = trim((string)($data['emaillist'] ?? ''));
        $csv = trim($this->get_attachment_content());

        if ($text === '' && $csv === '') {
            $errors['emaillist'] = get_string('needsource', 'enrol_preenrol');
            return $errors;
        }

        // The limit counts parsed tokens, not physical lines, so both sources
        // have to be counted together.
        $tokens = importer::parse($text . "\n" . $csv);
        if (count($tokens) > self::MAX_ROWS) {
            $errorel = $text !== '' ? 'emaillist' : 'attachment';
            $errors[$errorel] = get_string('toomany', 'enrol_preenrol', self::MAX_ROWS);
        }

        return $errors;
    }

    /**
     * Raw content of the uploaded CSV file.
     *
     * Reads the file out of the user draft area of the attachment filepicker;
     * the picker is limited to a single file, but several chunks are joined
     * defensively with a newline so tokens can never run into each other.
     *
     * @return string empty string when no file was uploaded
     */
    public function get_attachment_content(): string {
        $files = $this->get_draft_files('attachment');
        if (empty($files)) {
            return '';
        }

        $chunks = [];
        foreach ($files as $file) {
            $chunks[] = $file->get_content();
        }
        return implode("\n", $chunks);
    }
}
