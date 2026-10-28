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

namespace mod_aispeakwrite\local;

/**
 * Tasks, who can see them, correction modes and limits.
 *
 * @package    mod_aispeakwrite
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class manager {
    /** @var string[] Writing task types. */
    public const WRITING_TYPES = ['email', 'translation', 'free'];

    /** @var string[] Speaking task types. */
    public const SPEAKING_TYPES = ['speak1', 'speak2', 'speak3'];

    /** @var int[] Score bands for each criterion. */
    public const BANDS = [0, 3, 9, 15];

    /** @var string[] Criteria keys, in display order. */
    public const CRITERIA = ['task', 'communication', 'form'];

    /** @var string[] Languages that can be learned, as BCP 47 tags. */
    public const LANGUAGES = ['de-DE', 'de-AT', 'de-CH', 'en-GB', 'en-US', 'fr-FR', 'es-ES', 'it-IT', 'nl-NL', 'pt-PT'];

    /** @var string[][] Informal and formal forms of address, by language (none for English). */
    public const ADDRESS = [
        'de' => ['du', 'Sie'], 'fr' => ['tu', 'vous'], 'es' => ['tú', 'usted'], 'it' => ['tu', 'Lei'],
        'nl' => ['jij', 'u'], 'pt' => ['tu', 'você'],
    ];

    /** @var string[][] The telc exam's own German names for the criteria, shown when German is being learned. */
    public const GERMAN_CRITERIA = [
        'writing' => ['task' => 'Aufgabenbewältigung', 'communication' => 'Kommunikative Gestaltung',
            'form' => 'Formale Richtigkeit'],
        'speaking' => ['task' => 'Aufgabenbewältigung', 'communication' => 'Ausdrucksfähigkeit',
            'form' => 'Formale Richtigkeit'],
    ];

    /**
     * The two-letter code of the language being learned ("de" for de-AT).
     *
     * @param string|null $targetlang
     * @return string
     */
    public static function language_family(?string $targetlang): string {
        $base = strtolower(substr((string)$targetlang, 0, 2));
        return in_array($base, ['de', 'en', 'fr', 'es', 'it', 'nl', 'pt'], true) ? $base : 'de';
    }

    /**
     * The default language being learned for new activities (site setting, German if unset).
     *
     * @return string
     */
    public static function default_language(): string {
        $lang = (string)get_config('mod_aispeakwrite', 'defaulttargetlang');
        return in_array($lang, self::LANGUAGES, true) ? $lang : 'de-DE';
    }

    /**
     * A criterion's name, in the interface language. For German, the telc exam's own name comes first.
     *
     * @param string $kind writing or speaking
     * @param string $key task, communication or form
     * @param string|null $targetlang
     * @return string
     */
    public static function criterion_label(string $kind, string $key, ?string $targetlang): string {
        $kind = $kind === 'speaking' ? 'speaking' : 'writing';
        $label = get_string('criterion_' . $kind . '_' . $key, 'mod_aispeakwrite');
        if (self::language_family($targetlang) === 'de' && isset(self::GERMAN_CRITERIA[$kind][$key])) {
            return self::GERMAN_CRITERIA[$kind][$key] . ' · ' . $label;
        }
        return $label;
    }

    /**
     * The form-of-address choices for a writing task, labelled for the language being learned.
     *
     * Stored values stay "du", "sie" and "any" (informal, formal, either) whatever the language.
     *
     * @param string|null $targetlang
     * @return string[]
     */
    public static function formality_options(?string $targetlang): array {
        $p = 'mod_aispeakwrite';
        $pair = self::ADDRESS[self::language_family($targetlang)] ?? null;
        return [
            'du' => $pair ? get_string('formality_informal', $p, $pair[0]) : get_string('formality_informal_plain', $p),
            'sie' => $pair ? get_string('formality_formal', $p, $pair[1]) : get_string('formality_formal_plain', $p),
            'any' => get_string('formality_any', $p),
        ];
    }

    /**
     * Cleans the activity settings before they are saved.
     *
     * @param \stdClass $data form data
     * @return \stdClass
     */
    public static function prepare_instance(\stdClass $data): \stdClass {
        $data->correctionmode = in_array($data->correctionmode ?? '', ['two', 'think', 'explain'], true)
            ? $data->correctionmode : 'two';
        foreach (['showtelc', 'showgood', 'sendhistory'] as $flag) {
            $data->$flag = empty($data->$flag) ? 0 : 1;
        }
        $data->dailylimit = max(0, min(50, (int)($data->dailylimit ?? 3)));
        $data->rewritelimit = max(0, min(10, (int)($data->rewritelimit ?? 2)));
        $data->activitytips = \core_text::substr(trim((string)($data->activitytips ?? '')), 0, tips::MAXCHARS);
        $data->examlevel = \core_text::substr(trim((string)($data->examlevel ?? 'B1')), 0, 10);
        $data->targetlang = in_array((string)($data->targetlang ?? ''), self::LANGUAGES, true)
            ? $data->targetlang : self::default_language();
        $data->feedbacklang = preg_match('/^[a-z]{2,3}(_[a-z]{2,4})?$/', (string)($data->feedbacklang ?? ''))
            ? $data->feedbacklang : '';
        $data->examdate = (int)($data->examdate ?? 0);
        if (isset($data->completionmarked)) {
            // Left unset when completion is locked, so the stored value is kept.
            $data->completionmarked = max(0, (int)$data->completionmarked);
        }
        return $data;
    }

    /**
     * The feedback mode for a draft number.
     *
     * @param \stdClass $instance
     * @param int $attempt
     * @return string think or explain
     */
    public static function mode_for_attempt(\stdClass $instance, int $attempt): string {
        if ($instance->correctionmode === 'think') {
            return 'think';
        }
        if ($instance->correctionmode === 'explain') {
            return 'explain';
        }
        return $attempt <= 1 ? 'think' : 'explain';
    }

    /**
     * All tasks of an activity, in order.
     *
     * @param int $aid
     * @param bool $visibleonly
     * @return \stdClass[]
     */
    public static function tasks(int $aid, bool $visibleonly = false): array {
        global $DB;
        $params = ['aispeakwriteid' => $aid];
        if ($visibleonly) {
            $params['visible'] = 1;
        }
        return $DB->get_records('aispeakwrite_task', $params, 'sortorder ASC, id ASC');
    }

    /**
     * One task of an activity.
     *
     * @param int $aid
     * @param int $taskid
     * @return \stdClass
     */
    public static function task(int $aid, int $taskid): \stdClass {
        global $DB;
        return $DB->get_record('aispeakwrite_task', ['id' => $taskid, 'aispeakwriteid' => $aid], '*', MUST_EXIST);
    }

    /**
     * Whether a learner may see a task.
     *
     * @param \stdClass $task
     * @param int $userid
     * @param array $usergroups group ids of the user in the course
     * @param array $assigned task ids assigned to the user
     * @return bool
     */
    public static function task_for_user(\stdClass $task, int $userid, array $usergroups, array $assigned): bool {
        if (empty($task->visible)) {
            return false;
        }
        if ($task->audience === 'group') {
            return in_array((int)$task->groupid, $usergroups, true) || in_array((int)$task->id, $assigned, true);
        }
        if ($task->audience === 'selected') {
            return in_array((int)$task->id, $assigned, true);
        }
        return true;
    }

    /**
     * The tasks a learner can see, with assignment details.
     *
     * @param \stdClass $instance
     * @param \cm_info|\stdClass $cm
     * @param int $userid
     * @return \stdClass[] tasks with extra fields assigned (bool) and due (int)
     */
    public static function tasks_for_user(\stdClass $instance, $cm, int $userid): array {
        global $DB;
        $tasks = self::tasks((int)$instance->id, true);
        if (!$tasks) {
            return [];
        }
        [$in, $params] = $DB->get_in_or_equal(array_keys($tasks), SQL_PARAMS_NAMED);
        $params['userid'] = $userid;
        $assignrows = $DB->get_records_select('aispeakwrite_taskuser', "taskid $in AND userid = :userid", $params);
        $assigned = [];
        $due = [];
        foreach ($assignrows as $row) {
            $assigned[] = (int)$row->taskid;
            $due[(int)$row->taskid] = (int)$row->duedate;
        }
        $groups = [];
        foreach (groups_get_all_groups((int)$instance->course, $userid) as $g) {
            $groups[] = (int)$g->id;
        }
        $out = [];
        foreach ($tasks as $task) {
            if (!self::task_for_user($task, $userid, $groups, $assigned)) {
                continue;
            }
            $task->assigned = in_array((int)$task->id, $assigned, true) || $task->audience !== 'all';
            $task->due = $due[(int)$task->id] ?? 0;
            if (!$task->due) {
                $task->due = (int)$task->duedate;
            }
            $out[$task->id] = $task;
        }
        return $out;
    }

    /**
     * Marked or pending answers a learner has for a task.
     *
     * @param int $taskid
     * @param int $userid
     * @return int
     */
    public static function attempts_used(int $taskid, int $userid): int {
        global $DB;
        return $DB->count_records_select(
            'aispeakwrite_sub',
            "taskid = :taskid AND userid = :userid
            AND status IN ('pending', 'marked')",
            ['taskid' => $taskid, 'userid' => $userid]
        );
    }

    /**
     * Markings a learner has used today in this activity.
     *
     * @param int $aid
     * @param int $userid
     * @return int
     */
    public static function markings_today(int $aid, int $userid): int {
        global $DB;
        return $DB->count_records_select(
            'aispeakwrite_op',
            "aispeakwriteid = :aid AND userid = :userid AND kind = 'mark'
            AND state IN ('pending', 'complete') AND timecreated >= :since",
            ['aid' => $aid, 'userid' => $userid, 'since' => usergetmidnight(time())]
        );
    }

    /**
     * Markings left today (null when there is no daily limit).
     *
     * @param \stdClass $instance
     * @param int $userid
     * @return int|null
     */
    public static function markings_left(\stdClass $instance, int $userid): ?int {
        if (empty($instance->dailylimit)) {
            return null;
        }
        return max(0, (int)$instance->dailylimit - self::markings_today((int)$instance->id, $userid));
    }

    /**
     * Whether a learner may start another draft of a task.
     *
     * @param \stdClass $instance
     * @param int $taskid
     * @param int $userid
     * @return bool
     */
    public static function can_start_draft(\stdClass $instance, int $taskid, int $userid): bool {
        return self::attempts_used($taskid, $userid) < 1 + (int)$instance->rewritelimit;
    }

    /**
     * The nearest score band.
     *
     * @param mixed $value
     * @return int
     */
    public static function band($value): int {
        $v = is_numeric($value) ? (float)$value : 0.0;
        $best = 0;
        foreach (self::BANDS as $band) {
            if (abs($band - $v) < abs($best - $v)) {
                $best = $band;
            }
        }
        return $best;
    }

    /**
     * Word count of a text.
     *
     * @param string $text
     * @return int
     */
    public static function words(string $text): int {
        $text = trim($text);
        if ($text === '') {
            return 0;
        }
        return count(preg_split('/\s+/u', $text));
    }

    /**
     * The fewest words a task needs before it is marked.
     *
     * @param \stdClass $task
     * @return int
     */
    public static function min_words(\stdClass $task): int {
        if ($task->kind === 'speaking') {
            return 15;
        }
        return $task->tasktype === 'translation' ? 3 : 20;
    }

    /**
     * The feedback language for a learner.
     *
     * @param \stdClass $instance
     * @return string Moodle language code
     */
    public static function feedback_language(\stdClass $instance): string {
        return $instance->feedbacklang !== '' ? $instance->feedbacklang : current_language();
    }

    /**
     * Next sort order for a new task.
     *
     * @param int $aid
     * @return int
     */
    public static function next_sortorder(int $aid): int {
        global $DB;
        return (int)$DB->get_field_sql(
            'SELECT COALESCE(MAX(sortorder), 0) + 1 FROM {aispeakwrite_task}
            WHERE aispeakwriteid = ?',
            [$aid]
        );
    }

    /**
     * Deletes a task with its answers and assignments.
     *
     * @param \stdClass $task
     */
    public static function delete_task(\stdClass $task): void {
        global $DB;
        $DB->delete_records('aispeakwrite_taskuser', ['taskid' => $task->id]);
        $DB->delete_records('aispeakwrite_sub', ['taskid' => $task->id]);
        $DB->delete_records('aispeakwrite_task', ['id' => $task->id]);
    }

    /**
     * Assigns a task to learners.
     *
     * @param \stdClass $task
     * @param int[] $userids
     * @param int $duedate
     * @param int $by
     * @return int number newly assigned
     */
    public static function assign(\stdClass $task, array $userids, int $duedate, int $by): int {
        global $DB;
        $n = 0;
        foreach (array_unique(array_map('intval', $userids)) as $userid) {
            $row = $DB->get_record('aispeakwrite_taskuser', ['taskid' => $task->id, 'userid' => $userid]);
            if ($row) {
                $DB->set_field('aispeakwrite_taskuser', 'duedate', $duedate, ['id' => $row->id]);
                continue;
            }
            $DB->insert_record(
                'aispeakwrite_taskuser',
                (object)['taskid' => $task->id, 'userid' => $userid,
                'duedate' => $duedate,
                'assignedby' => $by,
                'timecreated' => time()]
            );
            $n++;
        }
        return $n;
    }
}
