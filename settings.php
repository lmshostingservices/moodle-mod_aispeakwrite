<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Site settings for mod_aispeakwrite.
 *
 * @package    mod_aispeakwrite
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $p = 'mod_aispeakwrite';

    $settings->add(
        new admin_setting_heading(
            'mod_aispeakwrite/tipsheading',
            get_string('schooltipsheading', $p),
            get_string('schooltipsheading_desc', $p)
        )
    );
    $settings->add(
        new \mod_aispeakwrite\admin\setting_tips(
            'mod_aispeakwrite/schooltips',
            get_string('schooltips', $p),
            get_string('schooltips_desc', $p),
            '',
            PARAM_TEXT,
            70,
            12
        )
    );

    $settings->add(
        new admin_setting_heading(
            'mod_aispeakwrite/lmslabsheading',
            get_string('lmslabsheading', $p),
            get_string('lmslabsheading_desc', $p) . ' ' .
            get_string(\mod_aispeakwrite\local\credentials::status(), $p)
        )
    );
    $settings->add(
        new admin_setting_configtext(
            'mod_aispeakwrite/lmslabssiteid',
            get_string('lmslabssiteid', $p),
            get_string('lmslabssiteid_desc', $p),
            '',
            PARAM_ALPHANUMEXT
        )
    );
    $settings->add(
        new admin_setting_configpasswordunmask(
            'mod_aispeakwrite/lmslabsapikey',
            get_string('lmslabsapikey', $p),
            get_string('lmslabsapikey_desc', $p),
            ''
        )
    );

    $settings->add(new admin_setting_heading('mod_aispeakwrite/defaultsheading', get_string('defaultsheading', $p), ''));
    $languages = [];
    foreach (\mod_aispeakwrite\local\manager::LANGUAGES as $code) {
        $languages[$code] = get_string('lang_' . str_replace('-', '', strtolower($code)), $p);
    }
    $settings->add(
        new admin_setting_configselect(
            'mod_aispeakwrite/defaulttargetlang',
            get_string('defaulttargetlang', $p),
            get_string('defaulttargetlang_desc', $p),
            'de-DE',
            $languages
        )
    );
    $settings->add(
        new admin_setting_configtext(
            'mod_aispeakwrite/defaultdailylimit',
            get_string('dailylimit', $p),
            get_string('dailylimit_help', $p),
            3,
            PARAM_INT
        )
    );
    $settings->add(
        new admin_setting_configcheckbox(
            'mod_aispeakwrite/partner',
            get_string('partnerenabled', $p),
            get_string('partnerenabled_desc', $p),
            1
        )
    );
    $settings->add(
        new admin_setting_configtext(
            'mod_aispeakwrite/lowcredits',
            get_string('lowcredits', $p),
            get_string('lowcredits_desc', $p),
            500,
            PARAM_INT
        )
    );
}
