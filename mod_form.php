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
 * Activity settings form.
 *
 * @package    mod_aispeakwrite
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

use mod_aispeakwrite\local\tips;

/**
 * Activity settings form.
 *
 * @package    mod_aispeakwrite
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_aispeakwrite_mod_form extends moodleform_mod {
    /**
     * Form definition.
     */
    public function definition() {
        $mform = $this->_form;
        $p = 'mod_aispeakwrite';

        $mform->addElement('header', 'general', get_string('general', 'form'));
        $mform->addElement('text', 'name', get_string('name'), ['size' => 64]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $this->standard_intro_elements();

        $mform->addElement('header', 'feedbackhdr', get_string('feedbacksettings', $p));
        $mform->setExpanded('feedbackhdr');
        $mform->addElement('select', 'correctionmode', get_string('correctionmode', $p), [
            'two' => get_string('mode_two', $p),
            'think' => get_string('mode_thinkonly', $p),
            'explain' => get_string('mode_explainonly', $p),
        ]);
        $mform->addHelpButton('correctionmode', 'correctionmode', $p);
        $mform->setDefault('correctionmode', 'two');
        $mform->addElement('advcheckbox', 'showtelc', get_string('showtelc', $p));
        $mform->addHelpButton('showtelc', 'showtelc', $p);
        $mform->setDefault('showtelc', 1);
        $mform->addElement('advcheckbox', 'showgood', get_string('showgood', $p));
        $mform->setDefault('showgood', 1);
        $mform->addElement('advcheckbox', 'sendhistory', get_string('sendhistory', $p));
        $mform->addHelpButton('sendhistory', 'sendhistory', $p);
        $mform->setDefault('sendhistory', 1);

        $mform->addElement('textarea', 'activitytips', get_string('activitytips', $p), ['rows' => 8, 'cols' => 70,
            'maxlength' => tips::MAXCHARS]);
        $mform->setType('activitytips', PARAM_TEXT);
        $mform->addHelpButton('activitytips', 'tipsformat', $p);

        $mform->addElement('header', 'examhdr', get_string('examsettings', $p));
        $mform->addElement('text', 'examlevel', get_string('examlevel', $p), ['size' => 8]);
        $mform->setType('examlevel', PARAM_ALPHANUMEXT);
        $mform->setDefault('examlevel', 'B1');
        $mform->addElement('select', 'targetlang', get_string('targetlang', $p), self::target_languages());
        $mform->setDefault('targetlang', 'de-DE');
        $langs = ['' => get_string('feedbacklang_learner', $p)] + get_string_manager()->get_list_of_translations();
        $mform->addElement('select', 'feedbacklang', get_string('feedbacklang', $p), $langs);
        $mform->addHelpButton('feedbacklang', 'feedbacklang', $p);
        $mform->addElement('date_selector', 'examdate', get_string('examdate', $p), ['optional' => true]);

        $mform->addElement('header', 'limitshdr', get_string('limits', $p));
        $mform->addElement('text', 'dailylimit', get_string('dailylimit', $p), ['size' => 4]);
        $mform->setType('dailylimit', PARAM_INT);
        $mform->setDefault('dailylimit', (int)(get_config($p, 'defaultdailylimit') ?: 3));
        $mform->addHelpButton('dailylimit', 'dailylimit', $p);
        $mform->addElement('text', 'rewritelimit', get_string('rewritelimit', $p), ['size' => 4]);
        $mform->setType('rewritelimit', PARAM_INT);
        $mform->setDefault('rewritelimit', 2);
        $mform->addHelpButton('rewritelimit', 'rewritelimit', $p);

        $this->standard_grading_coursemodule_elements();
        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * Languages a learner can practise.
     *
     * @return string[]
     */
    public static function target_languages(): array {
        $out = [];
        foreach (['de-DE', 'de-AT', 'de-CH', 'en-GB', 'en-US', 'fr-FR', 'es-ES', 'it-IT', 'nl-NL', 'pt-PT'] as $code) {
            $out[$code] = get_string('lang_' . str_replace('-', '', strtolower($code)), 'mod_aispeakwrite');
        }
        return $out;
    }

    /**
     * Validation.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (\core_text::strlen((string)($data['activitytips'] ?? '')) > tips::MAXCHARS) {
            $errors['activitytips'] = get_string('toolong', 'mod_aispeakwrite', tips::MAXCHARS);
        }
        if ((int)($data['dailylimit'] ?? 0) < 0 || (int)($data['dailylimit'] ?? 0) > 50) {
            $errors['dailylimit'] = get_string('between', 'mod_aispeakwrite', ['min' => 0, 'max' => 50]);
        }
        if ((int)($data['rewritelimit'] ?? 0) < 0 || (int)($data['rewritelimit'] ?? 0) > 10) {
            $errors['rewritelimit'] = get_string('between', 'mod_aispeakwrite', ['min' => 0, 'max' => 10]);
        }
        $suffix = $this->get_suffix();
        if (!empty($data['completionmarkedenabled' . $suffix]) && (int)($data['completionmarked' . $suffix] ?? 0) < 1) {
            $errors['completionmarkedgroup' . $suffix] = get_string('completionmarkedmin', 'mod_aispeakwrite');
        }
        return $errors;
    }

    /**
     * Custom completion rule: number of tasks marked.
     *
     * @return string[]
     */
    public function add_completion_rules() {
        $mform = $this->_form;
        $suffix = $this->get_suffix();
        $group = [
            $mform->createElement(
                'checkbox',
                'completionmarkedenabled' . $suffix,
                '',
                get_string('completionmarked', 'mod_aispeakwrite')
            ),
            $mform->createElement('text', 'completionmarked' . $suffix, '', ['size' => 3]),
        ];
        $mform->setType('completionmarked' . $suffix, PARAM_INT);
        $mform->addGroup(
            $group,
            'completionmarkedgroup' . $suffix,
            get_string('completionmarkedgroup', 'mod_aispeakwrite'),
            [' '],
            false
        );
        $mform->disabledIf('completionmarked' . $suffix, 'completionmarkedenabled' . $suffix, 'notchecked');
        return ['completionmarkedgroup' . $suffix];
    }

    /**
     * Whether the custom rule is on.
     *
     * @param array $data
     * @return bool
     */
    public function completion_rule_enabled($data) {
        $suffix = $this->get_suffix();
        return !empty($data['completionmarkedenabled' . $suffix]) && (int)($data['completionmarked' . $suffix] ?? 0) > 0;
    }

    /**
     * Fills the form from the stored record.
     *
     * @param array $defaultvalues
     */
    public function data_preprocessing(&$defaultvalues) {
        parent::data_preprocessing($defaultvalues);
        $suffix = $this->get_suffix();
        $n = (int)($defaultvalues['completionmarked'] ?? 0);
        $defaultvalues['completionmarkedenabled' . $suffix] = $n > 0 ? 1 : 0;
        $defaultvalues['completionmarked' . $suffix] = $n > 0 ? $n : 1;
    }

    /**
     * Turns the completion group back into the stored field.
     *
     * @param stdClass $data
     */
    public function data_postprocessing($data) {
        parent::data_postprocessing($data);
        $suffix = $this->get_suffix();
        if (!empty($data->completionunlocked)) {
            $enabled = !empty($data->{'completionmarkedenabled' . $suffix})
                && !empty($data->completion) && $data->completion == COMPLETION_TRACKING_AUTOMATIC;
            $data->completionmarked = $enabled ? max(1, (int)$data->{'completionmarked' . $suffix}) : 0;
        }
    }
}
