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
 * Import page for one pre-enrolment instance.
 *
 * Accepts a pasted address list and/or a CSV file, runs the bulk importer and
 * shows the category report plus the current pending pre-enrolment listing.
 *
 * @package    enrol_preenrol
 * @copyright  2026 Stadtschamane <https://github.com/stadtschamane>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');

$enrolid = required_param('enrolid', PARAM_INT);
$delete = optional_param('delete', 0, PARAM_INT);

$instance = $DB->get_record('enrol', ['id' => $enrolid, 'enrol' => 'preenrol'], '*', MUST_EXIST);
$course = $DB->get_record('course', ['id' => $instance->courseid], '*', MUST_EXIST);
$context = context_course::instance($course->id);

require_login($course);
require_capability('enrol/preenrol:import', $context);

$PAGE->set_url('/enrol/preenrol/import.php', ['enrolid' => $enrolid]);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('import', 'enrol_preenrol'));
$PAGE->set_heading($course->fullname);
$PAGE->navbar->add(get_string('enrolmentinstances', 'enrol'),
    new moodle_url('/enrol/instances.php', ['id' => $course->id]));
$PAGE->navbar->add(get_string('import', 'enrol_preenrol'));

// Post/Redirect/Get for deleting a single pending row. The sesskey is part of
// the Delete link rendered below.
if ($delete && confirm_sesskey()) {
    $pending = $DB->get_record('enrol_preenrol_pending', ['id' => $delete, 'enrolid' => $enrolid], '*', IGNORE_MISSING);
    if ($pending) {
        $DB->delete_records('enrol_preenrol_pending', ['id' => $pending->id, 'enrolid' => $enrolid]);
    }
    redirect($PAGE->url);
}

$formaction = new moodle_url('/enrol/preenrol/import.php', ['enrolid' => $enrolid]);
$mform = new \enrol_preenrol\import_form($formaction, ['enrolid' => $enrolid]);

$report = null;
if ($mform->is_cancelled()) {
    redirect($PAGE->url);
}
if ($data = $mform->get_data()) {
    $text = (string)($data->emaillist ?? '');
    $csv = $mform->get_attachment_content();
    if ($csv !== '') {
        $text = ($text === '') ? $csv : $text . "\n" . $csv;
    }
    $report = \enrol_preenrol\importer::import_text($enrolid, $text);

    // Offer an empty form again. A second instance still sees the submitted
    // values in $_POST, so the postback is hidden while it is constructed.
    $postbackup = $_POST;
    $_POST = [];
    $mform = new \enrol_preenrol\import_form($formaction, ['enrolid' => $enrolid]);
    $_POST = $postbackup;
}

echo $OUTPUT->header();

if ($report !== null) {
    echo $OUTPUT->heading(get_string('importreport', 'enrol_preenrol'));

    $summary = new html_table();
    $summary->attributes['class'] = 'generaltable';
    $summary->head = [
        get_string('importpending', 'enrol_preenrol'),
        get_string('importenrolled', 'enrol_preenrol'),
        get_string('importalreadyenrolled', 'enrol_preenrol'),
        get_string('importinvalid', 'enrol_preenrol'),
        get_string('importduplicate', 'enrol_preenrol'),
    ];
    $summary->data = [[
        $report['pending'],
        $report['enrolled'],
        $report['alreadyenrolled'],
        $report['invalid'],
        $report['duplicate'],
    ]];
    echo html_writer::table($summary);

    $lines = array_slice($report['lines'], 0, 25);
    if (!empty($lines)) {
        $detail = new html_table();
        $detail->attributes['class'] = 'generaltable';
        $detail->head = [get_string('email'), get_string('status')];
        foreach ($lines as $line) {
            $detail->data[] = [
                s($line['token']),
                get_string('import' . $line['category'], 'enrol_preenrol'),
            ];
        }
        echo html_writer::table($detail);
    }
}

$mform->display();

echo $OUTPUT->heading(get_string('importpendingheading', 'enrol_preenrol'));

$pending = $DB->get_records('enrol_preenrol_pending', ['enrolid' => $enrolid], 'email ASC');
$pendingtable = new html_table();
$pendingtable->attributes['class'] = 'generaltable';
$pendingtable->head = [
    get_string('email'),
    get_string('importtimecreated', 'enrol_preenrol'),
    get_string('action'),
];
$pendingtable->data = [];
foreach ($pending as $pendingrow) {
    $deleteurl = new moodle_url('/enrol/preenrol/import.php', [
        'enrolid' => $enrolid,
        'delete' => $pendingrow->id,
        'sesskey' => sesskey(),
    ]);
    $confirm = new \core\output\actions\confirm_action(
        get_string('importdeleteconfirm', 'enrol_preenrol', $pendingrow->email));
    $deleteicon = $OUTPUT->action_icon(
        $deleteurl,
        new pix_icon('t/delete', get_string('delete'), 'core', ['class' => 'iconsmall']),
        $confirm
    );
    $pendingtable->data[] = [
        s($pendingrow->email),
        userdate($pendingrow->timecreated),
        $deleteicon,
    ];
}
echo html_writer::table($pendingtable);

echo $OUTPUT->footer();
