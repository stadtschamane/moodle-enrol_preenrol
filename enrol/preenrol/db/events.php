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
 * Pre-enrolment plugin event handler definition.
 *
 * The observer must be an internal handler: it enrols the user via
 * enrol_plugin::enrol_user() which writes Moodle's own enrolment tables,
 * so the enrolment must be atomic with the account creation transaction
 * (and it stays dispatchable under PHPUnit's rollback-based tests).
 *
 * @package    enrol_preenrol
 * @category   event
 * @copyright  2026 Stadtschamane <https://github.com/stadtschamane>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\core\event\user_created',
        'callback' => '\enrol_preenrol\observer::user_created',
        'internal' => true,
    ],
];
