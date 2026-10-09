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
 * Pre-enrolment bulk importer.
 *
 * Turns a raw list of e-mail addresses (separated by newlines, semicolons,
 * commas or tabs) into pending pre-enrolment rows, or enrols existing users
 * straight away. Every token lands in exactly one report category:
 * pending, enrolled, alreadyenrolled, invalid or duplicate. Capability and
 * sesskey checks belong to the calling UI layer (import.php), not here.
 *
 * @package    enrol_preenrol
 * @copyright  2026 Stadtschamane <https://github.com/stadtschamane>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace enrol_preenrol;

defined('MOODLE_INTERNAL') || die();

use context_course;
use dml_exception;

/**
 * Bulk import of e-mail addresses for one pre-enrolment instance.
 *
 * @package    enrol_preenrol
 * @copyright  2026 Stadtschamane <https://github.com/stadtschamane>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class importer {

    /**
     * Import a raw list of e-mail addresses into one pre-enrolment instance.
     *
     * @param int $enrolid Id of the enrol_preenrol instance.
     * @param string $text Raw token list.
     * @return array Report with keys pending, enrolled, alreadyenrolled,
     *               invalid, duplicate and lines (one entry per non-empty
     *               token, in input order).
     */
    public static function import_text(int $enrolid, string $text): array {
        global $DB;

        $instance = $DB->get_record('enrol', ['id' => $enrolid, 'enrol' => 'preenrol'], '*', MUST_EXIST);
        $plugin = enrol_get_plugin('preenrol');
        $context = context_course::instance($instance->courseid);

        $report = [
            'pending' => 0,
            'enrolled' => 0,
            'alreadyenrolled' => 0,
            'invalid' => 0,
            'duplicate' => 0,
            'lines' => [],
        ];

        // Addresses already handled in this import, for in-text duplicate detection.
        $seen = [];

        foreach (self::parse($text) as $token) {
            $cleaned = clean_param($token, PARAM_EMAIL);
            if ($cleaned !== $token) {
                $report['invalid']++;
                $report['lines'][] = ['token' => $token, 'category' => 'invalid', 'email' => null];
                continue;
            }
            $email = $cleaned;

            if (isset($seen[$email])) {
                $report['duplicate']++;
                $report['lines'][] = ['token' => $token, 'category' => 'duplicate', 'email' => $email];
                continue;
            }
            $seen[$email] = true;

            $user = $DB->get_record('user', ['email' => $email, 'deleted' => 0]);

            if ($user && is_enrolled($context, $user->id)) {
                $report['alreadyenrolled']++;
                $report['lines'][] = ['token' => $token, 'category' => 'alreadyenrolled', 'email' => $email];
                continue;
            }

            if ($user) {
                $plugin->enrol_user($instance, $user->id, (int)$instance->roleid);
                $report['enrolled']++;
                $report['lines'][] = ['token' => $token, 'category' => 'enrolled', 'email' => $email];
                continue;
            }

            // No user yet: queue the address. Idempotency comes from the
            // UNIQUE(enrolid, email) index - the re-import violation is caught
            // and reported as duplicate, no pre-select needed.
            try {
                $DB->insert_record('enrol_preenrol_pending', (object)[
                    'enrolid' => $enrolid,
                    'courseid' => $instance->courseid,
                    'email' => $email,
                    'roleid' => null,
                    'timecreated' => time(),
                ]);
                $report['pending']++;
                $report['lines'][] = ['token' => $token, 'category' => 'pending', 'email' => $email];
            } catch (dml_exception $e) {
                $report['duplicate']++;
                $report['lines'][] = ['token' => $token, 'category' => 'duplicate', 'email' => $email];
            }
        }

        return $report;
    }

    /**
     * Split raw text into normalised e-mail tokens.
     *
     * Tokens are separated by single newlines, semicolons, commas or tabs
     * (a single-character character class, deliberately without a quantifier).
     * Each token is trimmed and lowercased; empty tokens are dropped and do
     * not produce a line entry.
     *
     * @param string $text Raw token list.
     * @return string[] Normalised, non-empty tokens in input order.
     */
    protected static function parse(string $text): array {
        $tokens = preg_split('/[\r\n;\t,]/', $text);
        if ($tokens === false) {
            return [];
        }

        $items = [];
        foreach ($tokens as $token) {
            $token = strtolower(trim($token));
            if ($token === '') {
                continue;
            }
            $items[] = $token;
        }
        return $items;
    }
}