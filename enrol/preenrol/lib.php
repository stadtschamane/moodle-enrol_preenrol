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
 * Pre-enrolment enrolment plugin.
 *
 * @package    enrol_preenrol
 * @copyright  2026 Stadtschamane <https://github.com/stadtschamane>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

class enrol_preenrol_plugin extends enrol_plugin {

    /**
     * We are a good plugin and don't invent our own UI/validation code path.
     *
     * @return boolean
     */
    public function use_standard_editing_ui(): bool {
        return true;
    }

    /**
     * Return true if we can add a new instance to this course.
     *
     * @param int $courseid
     * @return bool
     */
    public function can_add_instance($courseid) {
        global $DB;

        $context = context_course::instance($courseid);
        if (!has_capability('moodle/course:enrolconfig', $context) || !has_capability('enrol/preenrol:config', $context)) {
            return false;
        }

        if ($DB->record_exists('enrol', array('courseid' => $courseid, 'enrol' => 'preenrol'))) {
            return false;
        }

        return true;
    }

    /**
     * Returns defaults for new instances.
     *
     * @return array
     */
    public function get_instance_defaults(): array {
        $fields['status'] = ENROL_INSTANCE_ENABLED;
        $fields['roleid'] = (int)$this->get_config('roleid');
        $fields['enrolperiod'] = 0;
        return $fields;
    }

    /**
     * Return an array of valid options for the status.
     *
     * @return array
     */
    protected function get_status_options() {
        $options = array(ENROL_INSTANCE_ENABLED => get_string('yes'),
                         ENROL_INSTANCE_DISABLED => get_string('no'));
        return $options;
    }

    /**
     * Add elements to the edit instance form.
     *
     * @param stdClass $instance
     * @param MoodleQuickForm $mform
     * @param context $coursecontext
     * @return bool
     */
    public function edit_instance_form($instance, MoodleQuickForm $mform, $coursecontext) {
        $options = $this->get_status_options();
        $mform->addElement('select', 'status', get_string('status', 'enrol_preenrol'), $options);

        $mform->addElement('text', 'name', get_string('custominstancename', 'enrol'));
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255);
        $mform->setDefault('name', (string)$this->get_config('name'));

        $roles = get_assignable_roles($coursecontext, ROLENAME_BOTH);
        $mform->addElement('select', 'roleid', get_string('assignrole', 'enrol_preenrol'), $roles);
        $mform->setDefault('roleid', $this->get_config('roleid'));

        $options = array('optional' => true, 'defaultunit' => 86400);
        $mform->addElement('duration', 'enrolperiod', get_string('enrolperiod', 'enrol_preenrol'), $options);
        $mform->setDefault('enrolperiod', 0);

        $options = array('optional' => true);
        $mform->addElement('date_time_selector', 'enrolstartdate', get_string('enrolstartdate', 'enrol_preenrol'), $options);
        $mform->setDefault('enrolstartdate', 0);

        $options = array('optional' => true);
        $mform->addElement('date_time_selector', 'enrolenddate', get_string('enrolenddate', 'enrol_preenrol'), $options);
        $mform->setDefault('enrolenddate', 0);
    }

    /**
     * Perform custom validation of the data used to edit the instance.
     *
     * @param array $data array of ("fieldname" => value) of submitted data
     * @param array $files array of uploaded files "element_name" => tmp_file_path
     * @param object $instance The instance loaded from the DB
     * @param context $context The context of the instance we are editing
     * @return array of "element_name" => "error_description" if there are errors,
     *         or an empty array if everything is OK.
     */
    public function edit_instance_validation($data, $files, $instance, $context) {
        $errors = array();

        if ((int)$data['enrolenddate'] > 0 && (int)$data['enrolenddate'] < (int)$data['enrolstartdate']) {
            $errors['enrolenddate'] = get_string('invalidtime', 'enrol_preenrol');
        }

        $validroles = array_keys(get_assignable_roles($context, ROLENAME_BOTH));
        if (!in_array((int)$data['roleid'], $validroles, true)) {
            $errors['roleid'] = get_string('invaliddata', 'error');
        }

        $typeerrors = $this->validate_param_types($data, array('status' => array_keys($this->get_status_options())));
        $errors = array_merge($errors, $typeerrors);

        return $errors;
    }

    /**
     * Add new instance of enrol plugin.
     *
     * @param object $course
     * @param array|null $fields instance fields
     * @return int id of new instance, null if can not be created
     */
    public function add_instance($course, ?array $fields = null) {
        return parent::add_instance($course, $fields);
    }

    /**
     * Delete course enrol plugin instance, unenrol all users.
     *
     * @param object $instance
     * @param null $oldid
     * @return void
     */
    public function delete_instance($instance, $oldid = null) {
        global $DB;

        $DB->delete_records('enrol_preenrol_pending', array('enrolid' => $instance->id));

        return parent::delete_instance($instance);
    }

    /**
     * Returns localised name of enrol instance.
     *
     * @param stdClass $instance (null is accepted too)
     * @return string
     */
    public function get_instance_name($instance) {
        global $DB;

        if (empty($instance)) {
            return get_string('pluginname', 'enrol_preenrol');
        }
        if (!empty($instance->name)) {
            return format_string($instance->name, true, array('context' => context_course::instance($instance->courseid)));
        } else {
            $count = $DB->count_records('enrol_preenrol_pending', array('enrolid' => $instance->id));
            if ($count > 0) {
                return get_string('instancename', 'enrol_preenrol', (int)$count);
            }
            return get_string('instancenamezero', 'enrol_preenrol');
        }
    }

    /**
     * Returns edit icons for the page with list of instances.
     *
     * @param stdClass $instance
     * @return array
     */
    public function get_action_icons(stdClass $instance) {
        global $OUTPUT;

        $icons = parent::get_action_icons($instance);
        $context = context_course::instance($instance->courseid);
        if (has_capability('enrol/preenrol:import', $context)) {
            $icons[] = $OUTPUT->action_icon(
                new moodle_url('/enrol/preenrol/import.php', array('enrolid' => $instance->id)),
                new pix_icon('t/add', get_string('import', 'enrol_preenrol'), 'core', array('class' => 'iconsmall'))
            );
        }
        return $icons;
    }
}
