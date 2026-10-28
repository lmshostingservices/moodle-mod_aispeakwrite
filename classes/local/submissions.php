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
 * Learners' drafts and marked answers.
 *
 * @package    mod_aispeakwrite
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class submissions {
    /** @var string Table name. */
    public const TABLE = 'aispeakwrite_sub';

    /**
     * The learner's open draft for a task (or the one being marked), creating one when allowed.
     *
     * A new draft after a marked one starts from the last marked text, so a rewrite edits what was written.
     *
     * @param \stdClass $instance
     * @param \stdClass $task
     * @param int $userid
     * @return \stdClass|null null when no rewrites are left
     */
    public static function open_draft(\stdClass $instance, \stdClass $task, int $userid): ?\stdClass {
        global $DB;
        $open = $DB->get_records_select(
            self::TABLE,
            "taskid = :taskid AND userid = :userid
            AND status IN ('draft', 'pending', 'failed')",
            ['taskid' => $task->id, 'userid' => $userid],
            'id DESC',
            '*',
            0,
            1
        );
        if ($open) {
            return reset($open);
        }
        if (!manager::can_start_draft($instance, (int)$task->id, $userid)) {
            return null;
        }
        $last = self::latest_marked((int)$task->id, $userid);
        $attempt = (int)$DB->get_field_sql(
            'SELECT COALESCE(MAX(attempt), 0) + 1 FROM {' . self::TABLE . '}
            WHERE taskid = ? AND userid = ?',
            [$task->id, $userid]
        );
        $now = time();
        $sub = (object)[
            'aispeakwriteid' => $instance->id, 'taskid' => $task->id, 'userid' => $userid, 'attempt' => $attempt,
            'kind' => $task->kind, 'status' => 'draft',
            'content' => ($last && $task->kind === 'writing') ? $last->content : '',
            'notes' => $last ? (string)$last->notes : '',
            'fbmode' => manager::mode_for_attempt($instance, $attempt), 'metrics' => '', 'feedback' => null,
            'contenthash' => '', 'tipsversion' => '', 'timecreated' => $now, 'timemodified' => $now, 'timemarked' => 0,
        ];
        $sub->id = $DB->insert_record(self::TABLE, $sub);
        return $sub;
    }

    /**
     * A learner's own submission.
     *
     * @param int $subid
     * @param int $aid
     * @param int $userid 0 to skip the owner check (teachers)
     * @return \stdClass
     */
    public static function get(int $subid, int $aid, int $userid = 0): \stdClass {
        global $DB;
        $params = ['id' => $subid, 'aispeakwriteid' => $aid];
        if ($userid) {
            $params['userid'] = $userid;
        }
        return $DB->get_record(self::TABLE, $params, '*', MUST_EXIST);
    }

    /**
     * Saves a writing draft.
     *
     * @param \stdClass $sub
     * @param string $content
     * @param string $notes
     */
    public static function save(\stdClass $sub, string $content, string $notes): void {
        global $DB;
        if (!in_array($sub->status, ['draft', 'failed'], true)) {
            throw new \moodle_exception('alreadymarked', 'mod_aispeakwrite');
        }
        $DB->update_record(
            self::TABLE,
            (object)[
                'id' => $sub->id,
                'content' => \core_text::substr(self::plain($content), 0, 20000),
                'notes' => \core_text::substr(self::plain($notes), 0, 4000),
                'timemodified' => time(),
            ]
        );
    }

    /**
     * Plain text as typed: tags stripped, line breaks kept.
     *
     * @param string $text
     * @return string
     */
    public static function plain(string $text): string {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = strip_tags($text);
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
    }

    /**
     * The learner's latest marked answer for a task.
     *
     * @param int $taskid
     * @param int $userid
     * @return \stdClass|null
     */
    public static function latest_marked(int $taskid, int $userid): ?\stdClass {
        global $DB;
        $rows = $DB->get_records(
            self::TABLE,
            ['taskid' => $taskid, 'userid' => $userid, 'status' => 'marked'],
            'attempt DESC',
            '*',
            0,
            1
        );
        return $rows ? reset($rows) : null;
    }

    /**
     * A learner's submissions in an activity, newest first.
     *
     * @param int $aid
     * @param int $userid
     * @param int $limit
     * @return \stdClass[]
     */
    public static function for_user(int $aid, int $userid, int $limit = 0): array {
        global $DB;
        return $DB->get_records_select(
            self::TABLE,
            "aispeakwriteid = :aid AND userid = :userid AND status <> 'draft'",
            ['aid' => $aid, 'userid' => $userid],
            'timemodified DESC, id DESC',
            '*',
            0,
            $limit
        );
    }

    /**
     * The feedback of a marked submission.
     *
     * @param \stdClass $sub
     * @return array
     */
    public static function feedback(\stdClass $sub): array {
        $data = json_decode((string)$sub->feedback, true);
        return is_array($data) ? $data : [];
    }

    /**
     * The learner's best percentage per task.
     *
     * @param int $aid
     * @param int $userid
     * @return float[] taskid => percent
     */
    public static function best_by_task(int $aid, int $userid): array {
        global $DB;
        $rows = $DB->get_records_sql(
            "SELECT taskid, MAX(score / maxscore) AS best
                                        FROM {" . self::TABLE . "}
                                       WHERE aispeakwriteid = :aid AND userid = :userid AND status = 'marked'
                                             AND maxscore > 0
                                    GROUP BY taskid",
            ['aid' => $aid, 'userid' => $userid]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int)$row->taskid] = round((float)$row->best * 100, 2);
        }
        return $out;
    }
}
