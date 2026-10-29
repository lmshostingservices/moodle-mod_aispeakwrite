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

namespace mod_aispeakwrite\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy API: what is stored, what is sent to LMS Labs, export and delete.
 *
 * @package    mod_aispeakwrite
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /** @var string[] Tables keyed by activity id that hold a userid column. */
    private const TABLES = ['aispeakwrite_sub', 'aispeakwrite_memory', 'aispeakwrite_op'];

    /**
     * Describes the data stored and sent.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'aispeakwrite_sub',
            [
                'userid' => 'privacy:metadata:aispeakwrite_sub:userid',
                'content' => 'privacy:metadata:aispeakwrite_sub:content',
                'notes' => 'privacy:metadata:aispeakwrite_sub:notes',
                'metrics' => 'privacy:metadata:aispeakwrite_sub:metrics',
                'feedback' => 'privacy:metadata:aispeakwrite_sub:feedback',
                'score' => 'privacy:metadata:aispeakwrite_sub:score',
                'timecreated' => 'privacy:metadata:aispeakwrite_sub:timecreated',
                'pastecount' => 'privacy:metadata:aispeakwrite_sub:pastecount',
                'pastechars' => 'privacy:metadata:aispeakwrite_sub:pastechars',
                'pasteblocked' => 'privacy:metadata:aispeakwrite_sub:pasteblocked',
                'tabswitches' => 'privacy:metadata:aispeakwrite_sub:tabswitches',
                'rewritechanged' => 'privacy:metadata:aispeakwrite_sub:rewritechanged',
            ],
            'privacy:metadata:aispeakwrite_sub'
        );
        $collection->add_database_table(
            'aispeakwrite_memory',
            [
                'userid' => 'privacy:metadata:aispeakwrite_memory:userid',
                'patternkey' => 'privacy:metadata:aispeakwrite_memory:patternkey',
                'label' => 'privacy:metadata:aispeakwrite_memory:label',
                'hits' => 'privacy:metadata:aispeakwrite_memory:hits',
                'misses' => 'privacy:metadata:aispeakwrite_memory:misses',
                'recent' => 'privacy:metadata:aispeakwrite_memory:recent',
            ],
            'privacy:metadata:aispeakwrite_memory'
        );
        $collection->add_database_table(
            'aispeakwrite_op',
            [
                'userid' => 'privacy:metadata:aispeakwrite_op:userid',
                'kind' => 'privacy:metadata:aispeakwrite_op:kind',
                'body' => 'privacy:metadata:aispeakwrite_op:body',
                'credits' => 'privacy:metadata:aispeakwrite_op:credits',
                'timecreated' => 'privacy:metadata:aispeakwrite_op:timecreated',
            ],
            'privacy:metadata:aispeakwrite_op'
        );
        $collection->add_database_table(
            'aispeakwrite_taskuser',
            [
                'userid' => 'privacy:metadata:aispeakwrite_taskuser:userid',
                'duedate' => 'privacy:metadata:aispeakwrite_taskuser:duedate',
            ],
            'privacy:metadata:aispeakwrite_taskuser'
        );
        $collection->add_external_location_link(
            'lmslabs',
            [
                'answer' => 'privacy:metadata:lmslabs:answer',
                'speech' => 'privacy:metadata:lmslabs:speech',
                'history' => 'privacy:metadata:lmslabs:history',
                'language' => 'privacy:metadata:lmslabs:language',
            ],
            'privacy:metadata:lmslabs'
        );
        $collection->add_external_location_link(
            'browser',
            [
                'audio' => 'privacy:metadata:browser:audio',
            ],
            'privacy:metadata:browser'
        );
        return $collection;
    }

    /**
     * Contexts with data for a user.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $base = "SELECT ctx.id
                   FROM {context} ctx
                   JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :level
                   JOIN {modules} m ON m.id = cm.module AND m.name = 'aispeakwrite'
                   JOIN {aispeakwrite} a ON a.id = cm.instance";
        foreach (self::TABLES as $table) {
            $contextlist->add_from_sql(
                "$base JOIN {{$table}} t ON t.aispeakwriteid = a.id WHERE t.userid = :userid",
                ['level' => CONTEXT_MODULE, 'userid' => $userid]
            );
        }
        $contextlist->add_from_sql(
            "$base JOIN {aispeakwrite_task} tk ON tk.aispeakwriteid = a.id
                                         JOIN {aispeakwrite_taskuser} tu ON tu.taskid = tk.id
                                        WHERE tu.userid = :userid",
            ['level' => CONTEXT_MODULE, 'userid' => $userid]
        );
        return $contextlist;
    }

    /**
     * Users with data in a context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }
        $params = ['cmid' => $context->instanceid];
        $base = "SELECT t.userid
                   FROM {course_modules} cm
                   JOIN {modules} m ON m.id = cm.module AND m.name = 'aispeakwrite'
                   JOIN {aispeakwrite} a ON a.id = cm.instance";
        foreach (self::TABLES as $table) {
            $userlist->add_from_sql(
                'userid',
                "$base JOIN {{$table}} t ON t.aispeakwriteid = a.id WHERE cm.id = :cmid",
                $params
            );
        }
        $userlist->add_from_sql(
            'userid',
            "$base JOIN {aispeakwrite_task} tk ON tk.aispeakwriteid = a.id
                                                 JOIN {aispeakwrite_taskuser} t ON t.taskid = tk.id
                                                WHERE cm.id = :cmid",
            $params
        );
    }

    /**
     * Exports a user's data.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        $userid = (int)$contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }
            $cm = get_coursemodule_from_id('aispeakwrite', $context->instanceid);
            if (!$cm) {
                continue;
            }
            $aid = (int)$cm->instance;
            $tasks = $DB->get_records('aispeakwrite_task', ['aispeakwriteid' => $aid], '', 'id, title');
            $answers = [];
            foreach ($DB->get_records('aispeakwrite_sub', ['aispeakwriteid' => $aid, 'userid' => $userid], 'id') as $s) {
                $answers[] = [
                    'task' => isset($tasks[$s->taskid]) ? format_string($tasks[$s->taskid]->title) : '',
                    'draft' => (int)$s->attempt,
                    'status' => $s->status,
                    'answer' => (string)$s->content,
                    'draftnote' => (string)$s->notes,
                    'speech' => (string)$s->metrics,
                    'feedback' => (string)$s->feedback,
                    'score' => $s->score,
                    'examscore' => $s->examscore,
                    'timecreated' => transform::datetime($s->timecreated),
                    'timemarked' => $s->timemarked ? transform::datetime($s->timemarked) : '',
                    'pastesfromoutside' => (int)$s->pastecount,
                    'pastedcharacters' => (int)$s->pastechars,
                    'pastesstopped' => (int)$s->pasteblocked,
                    'timesleftscreen' => (int)$s->tabswitches,
                    'rewritechangedpercent' => $s->rewritechanged === null ? '' : (int)$s->rewritechanged,
                    'rewriteflagged' => transform::yesno(!empty($s->rewriteflag)),
                ];
            }
            $memory = [];
            foreach ($DB->get_records('aispeakwrite_memory', ['aispeakwriteid' => $aid, 'userid' => $userid]) as $m) {
                $memory[] = ['pattern' => $m->label, 'right' => (int)$m->hits, 'missed' => (int)$m->misses,
                    'recent' => $m->recent];
            }
            $requests = [];
            foreach ($DB->get_records('aispeakwrite_op', ['aispeakwriteid' => $aid, 'userid' => $userid]) as $o) {
                $requests[] = ['kind' => $o->kind, 'state' => $o->state, 'credits' => (int)$o->credits,
                    'timecreated' => transform::datetime($o->timecreated)];
            }
            $assigned = [];
            if ($tasks) {
                [$in, $params] = $DB->get_in_or_equal(array_keys($tasks), SQL_PARAMS_NAMED);
                $params['userid'] = $userid;
                foreach ($DB->get_records_select('aispeakwrite_taskuser', "taskid $in AND userid = :userid", $params) as $tu) {
                    $assigned[] = ['task' => format_string($tasks[$tu->taskid]->title),
                        'due' => $tu->duedate ? transform::datetime($tu->duedate) : ''];
                }
            }
            if (!$answers && !$memory && !$requests && !$assigned) {
                continue;
            }
            writer::with_context($context)->export_data(
                [get_string('pluginname', 'mod_aispeakwrite')],
                (object)[
                    'answers' => $answers, 'focusareas' => $memory, 'requests' => $requests, 'assigned' => $assigned,
                ]
            );
        }
    }

    /**
     * Deletes everyone's data in a context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;
        if (!$context instanceof \context_module) {
            return;
        }
        $cm = get_coursemodule_from_id('aispeakwrite', $context->instanceid);
        if (!$cm) {
            return;
        }
        foreach (self::TABLES as $table) {
            $DB->delete_records($table, ['aispeakwriteid' => $cm->instance]);
        }
        $taskids = $DB->get_fieldset_select('aispeakwrite_task', 'id', 'aispeakwriteid = ?', [$cm->instance]);
        if ($taskids) {
            [$in, $params] = $DB->get_in_or_equal($taskids);
            $DB->delete_records_select('aispeakwrite_taskuser', "taskid $in", $params);
        }
    }

    /**
     * Deletes one user's data in the approved contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        $userid = (int)$contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            self::delete_users($context, [$userid]);
        }
    }

    /**
     * Deletes several users' data in a context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        self::delete_users($userlist->get_context(), $userlist->get_userids());
    }

    /**
     * Deletes the given users' data in a context.
     *
     * @param \context $context
     * @param int[] $userids
     */
    protected static function delete_users(\context $context, array $userids): void {
        global $DB;
        if (!$context instanceof \context_module || !$userids) {
            return;
        }
        $cm = get_coursemodule_from_id('aispeakwrite', $context->instanceid);
        if (!$cm) {
            return;
        }
        [$in, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        foreach (self::TABLES as $table) {
            $DB->delete_records_select($table, "aispeakwriteid = :aid AND userid $in", $params + ['aid' => $cm->instance]);
        }
        $taskids = $DB->get_fieldset_select('aispeakwrite_task', 'id', 'aispeakwriteid = ?', [$cm->instance]);
        if ($taskids) {
            [$tin, $tparams] = $DB->get_in_or_equal($taskids, SQL_PARAMS_NAMED, 't');
            $DB->delete_records_select('aispeakwrite_taskuser', "taskid $tin AND userid $in", $tparams + $params);
        }
        $instance = $DB->get_record('aispeakwrite', ['id' => $cm->instance]);
        if ($instance) {
            require_once(__DIR__ . '/../../lib.php');
            $instance->cmidnumber = $cm->idnumber;
            foreach ($userids as $userid) {
                aispeakwrite_update_grades($instance, (int)$userid);
            }
        }
    }
}
