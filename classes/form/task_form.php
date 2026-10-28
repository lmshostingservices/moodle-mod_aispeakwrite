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

namespace mod_aispeakwrite\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

use mod_aispeakwrite\local\manager;
use mod_aispeakwrite\local\tips;

/**
 * Adds or edits a writing prompt or speaking topic.
 *
 * @package    mod_aispeakwrite
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class task_form extends \moodleform {
    /**
     * Definition.
     */
    protected function definition() {
        $mform = $this->_form;
        $p = 'mod_aispeakwrite';
        $kind = $this->_customdata['kind'];
        $groups = $this->_customdata['groups'];
        $learners = $this->_customdata['learners'];

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'task');
        $mform->setType('task', PARAM_INT);
        $mform->addElement('hidden', 'kind', $kind);
        $mform->setType('kind', PARAM_ALPHA);

        $mform->addElement('header', 'taskhdr', get_string('kind_' . $kind, $p));
        $types = [];
        foreach ($kind === 'speaking' ? manager::SPEAKING_TYPES : manager::WRITING_TYPES as $type) {
            $types[$type] = get_string('tasktype_' . $type, $p);
        }
        $mform->addElement('select', 'tasktype', get_string('tasktype', $p), $types);
        $mform->addElement('text', 'title', get_string('tasktitle', $p), ['size' => 60]);
        $mform->setType('title', PARAM_TEXT);
        $mform->addRule('title', null, 'required', null, 'client');
        $mform->addRule('title', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $mform->addElement('text', 'topic', get_string('topic', $p), ['size' => 30]);
        $mform->setType('topic', PARAM_TEXT);
        $mform->addRule('topic', get_string('maximumchars', '', 100), 'maxlength', 100, 'client');
        $mform->addHelpButton('topic', 'topic', $p);

        $mform->addElement('textarea', 'situation', get_string('situation', $p), ['rows' => 3, 'cols' => 70]);
        $mform->setType('situation', PARAM_TEXT);
        $mform->addHelpButton('situation', 'situation', $p);
        $mform->addElement(
            'textarea',
            'sourcetext',
            get_string(
                $kind === 'speaking' ? 'sourcetext_speaking' : 'sourcetext',
                $p
            ),
            ['rows' => 8, 'cols' => 70]
        );
        $mform->setType('sourcetext', PARAM_TEXT);
        $mform->addElement('textarea', 'points', get_string('points', $p), ['rows' => 5, 'cols' => 70]);
        $mform->setType('points', PARAM_TEXT);
        $mform->addHelpButton('points', 'points', $p);

        if ($kind === 'writing') {
            $mform->addElement(
                'select',
                'formality',
                get_string('formality', $p),
                manager::formality_options($this->_customdata['targetlang'] ?? null)
            );
            $mform->addElement('text', 'targetwords', get_string('targetwords', $p), ['size' => 5]);
            $mform->setType('targetwords', PARAM_INT);
            $mform->setDefault('targetwords', 150);
        } else {
            $mform->addElement('hidden', 'formality', 'any');
            $mform->setType('formality', PARAM_ALPHA);
            $mform->addElement('hidden', 'targetwords', 0);
            $mform->setType('targetwords', PARAM_INT);
            $mform->addElement('textarea', 'partnerbrief', get_string('partnerbrief', $p), ['rows' => 4, 'cols' => 70]);
            $mform->setType('partnerbrief', PARAM_TEXT);
            $mform->addHelpButton('partnerbrief', 'partnerbrief', $p);
        }
        $mform->addElement('text', 'timelimit', get_string('timelimit', $p), ['size' => 5]);
        $mform->setType('timelimit', PARAM_INT);
        $mform->setDefault('timelimit', $kind === 'speaking' ? 5 : 30);

        $mform->addElement('header', 'tipshdr', get_string('tasktipshdr', $p));
        $mform->addElement(
            'textarea',
            'tasktips',
            get_string('tasktips', $p),
            ['rows' => 5, 'cols' => 70, 'maxlength' => tips::MAXCHARS]
        );
        $mform->setType('tasktips', PARAM_TEXT);
        $mform->addHelpButton('tasktips', 'tipsformat', $p);
        $mform->addElement('textarea', 'guide', get_string('guide', $p), ['rows' => 5, 'cols' => 70]);
        $mform->setType('guide', PARAM_TEXT);
        $mform->addHelpButton('guide', 'guide', $p);
        if ($kind === 'writing') {
            $mform->addElement('textarea', 'phrases', get_string('phrases', $p), ['rows' => 5, 'cols' => 70]);
            $mform->setType('phrases', PARAM_TEXT);
            $mform->addHelpButton('phrases', 'phrases', $p);
        }

        $mform->addElement('header', 'assignhdr', get_string('assignhdr', $p));
        $audiences = ['all' => get_string('audience_all', $p)];
        if ($groups) {
            $audiences['group'] = get_string('audience_group', $p);
        }
        $audiences['selected'] = get_string('audience_selected', $p);
        $mform->addElement('select', 'audience', get_string('audience', $p), $audiences);
        $mform->addHelpButton('audience', 'audience', $p);
        if ($groups) {
            $mform->addElement('select', 'groupid', get_string('group'), [0 => get_string('choosedots')] + $groups);
            $mform->hideIf('groupid', 'audience', 'neq', 'group');
        } else {
            $mform->addElement('hidden', 'groupid', 0);
        }
        $mform->setType('groupid', PARAM_INT);
        $mform->addElement(
            'autocomplete',
            'userids',
            get_string('assignto', $p),
            $learners,
            ['multiple' => true, 'noselectionstring' => get_string('nobody', $p)]
        );
        $mform->addHelpButton('userids', 'assignto', $p);
        $mform->addElement('date_time_selector', 'duedate', get_string('due', $p), ['optional' => true]);
        $mform->addElement('advcheckbox', 'visible', get_string('visibletolearners', $p));
        $mform->setDefault('visible', 1);

        $this->add_action_buttons();
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
        foreach (['tasktips'] as $field) {
            if (\core_text::strlen((string)($data[$field] ?? '')) > tips::MAXCHARS) {
                $errors[$field] = get_string('toolong', 'mod_aispeakwrite', tips::MAXCHARS);
            }
        }
        if (($data['audience'] ?? '') === 'group' && empty($data['groupid'])) {
            $errors['groupid'] = get_string('required');
        }
        if (($data['audience'] ?? '') === 'selected' && empty($data['userids'])) {
            $errors['userids'] = get_string('required');
        }
        if (count(tips::points($data['points'] ?? '')) === 0 && ($data['tasktype'] ?? '') === 'email') {
            $errors['points'] = get_string('pointsneeded', 'mod_aispeakwrite');
        }
        if ((int)($data['timelimit'] ?? 0) < 1 || (int)($data['timelimit'] ?? 0) > 120) {
            $errors['timelimit'] = get_string('between', 'mod_aispeakwrite', ['min' => 1, 'max' => 120]);
        }
        return $errors;
    }
}
