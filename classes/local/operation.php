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
 * Moodle's record of each paid LMS Labs request, saved with its key and exact body before anything is sent.
 *
 * States: pending (sent, or about to be; every try uses the same key and the same body), complete, failed (LMS Labs
 * refused it and charged nothing) and expired (LMS Labs no longer has it). A key is never rotated on an ambiguous answer.
 * The stored body is emptied as soon as the request is finished.
 *
 * @package    mod_aispeakwrite
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class operation {
    /** @var string Table name. */
    public const TABLE = 'aispeakwrite_op';

    /** @var int Seconds after which a request that hasn't finished can be cancelled by the learner or a teacher. */
    public const STALE = 3 * MINSECS;

    /**
     * When a submission's marking started: its oldest open request, or 0 when it has none.
     *
     * @param int $subid
     * @return int
     */
    public static function started(int $subid): int {
        global $DB;
        return (int)$DB->get_field_select(
            self::TABLE,
            'MIN(timecreated)',
            "itemid = :subid AND kind IN ('mark', 'converse') AND state = 'pending'",
            ['subid' => $subid]
        );
    }

    /**
     * Whether a submission is stuck being marked: pending, and its request has had no result for STALE seconds
     * (or there is no open request at all).
     *
     * @param \stdClass $sub
     * @return bool
     */
    public static function cancellable(\stdClass $sub): bool {
        if ($sub->status !== 'pending') {
            return false;
        }
        $started = self::started((int)$sub->id);
        return $started === 0 || $started <= time() - self::STALE;
    }

    /**
     * Ends a submission's open requests and makes the answer a draft again, so it can be submitted afresh.
     *
     * The learner's text is kept. A new submission gets a new request with a new key.
     *
     * @param int $subid
     * @return int number of requests ended
     */
    public static function release_submission(int $subid): int {
        global $DB;
        $ops = $DB->get_records_select(
            self::TABLE,
            "itemid = :subid AND kind IN ('mark', 'converse') AND state = 'pending'",
            ['subid' => $subid],
            '',
            'id'
        );
        $now = time();
        foreach ($ops as $op) {
            $DB->update_record(
                self::TABLE,
                (object)['id' => $op->id, 'state' => 'expired', 'body' => '', 'timemodified' => $now]
            );
        }
        $DB->set_field_select(
            'aispeakwrite_sub',
            'status',
            'draft',
            "id = :id AND status = 'pending'",
            ['id' => $subid]
        );
        return count($ops);
    }

    /**
     * The request to send: the pending one for this item (same key and body), or a new one.
     *
     * @param int $aid activity id
     * @param int $userid
     * @param string $kind mark, converse or test
     * @param int $itemid submission id (0 for a teacher test)
     * @param array $body exact JSON body for a new request
     * @return \stdClass the stored request; send json_decode($row->body, true)
     */
    public static function claim(int $aid, int $userid, string $kind, int $itemid, array $body): \stdClass {
        global $DB;
        $lock = \core\lock\lock_config::get_lock_factory('mod_aispeakwrite_operation')
            ->get_lock("$aid:$userid:$kind:$itemid", 10);
        if (!$lock) {
            throw new \moodle_exception('operationbusy', 'mod_aispeakwrite');
        }
        try {
            $rows = $DB->get_records(
                self::TABLE,
                ['aispeakwriteid' => $aid, 'userid' => $userid, 'kind' => $kind,
                'itemid' => $itemid,
                'state' => 'pending'],
                'id DESC',
                '*',
                0,
                1
            );
            if ($rows) {
                return reset($rows);
            }
            $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $now = time();
            $row = (object)[
                'aispeakwriteid' => $aid, 'userid' => $userid, 'kind' => $kind, 'itemid' => $itemid,
                'bodyhash' => hash('sha256', $json), 'body' => $json, 'idemkey' => \core\uuid::generate(),
                'state' => 'pending', 'credits' => 0, 'timecreated' => $now, 'timemodified' => $now,
            ];
            $row->id = $DB->insert_record(self::TABLE, $row);
            return $row;
        } finally {
            $lock->release();
        }
    }

    /**
     * The body to send for a stored request.
     *
     * @param \stdClass $row
     * @return array
     */
    public static function body(\stdClass $row): array {
        $body = json_decode((string)$row->body, true);
        return is_array($body) ? $body : [];
    }

    /**
     * Marks a request delivered and records what it cost.
     *
     * @param \stdClass $row
     * @param int $credits
     */
    public static function complete(\stdClass $row, int $credits): void {
        self::finish($row, 'complete', $credits);
    }

    /**
     * Ends a request LMS Labs refused (nothing charged), so the next click is a new request.
     *
     * @param \stdClass $row
     */
    public static function failed(\stdClass $row): void {
        self::finish($row, 'failed', 0);
    }

    /**
     * Ends a request LMS Labs no longer has (410).
     *
     * @param \stdClass $row
     */
    public static function expired(\stdClass $row): void {
        self::finish($row, 'expired', 0);
    }

    /**
     * Ends a request with a final state and empties its stored body.
     *
     * @param \stdClass $row
     * @param string $state
     * @param int $credits
     */
    protected static function finish(\stdClass $row, string $state, int $credits): void {
        global $DB;
        $DB->update_record(
            self::TABLE,
            (object)[
                'id' => $row->id, 'state' => $state, 'body' => '', 'credits' => $credits, 'timemodified' => time(),
            ]
        );
    }

    /**
     * Records the answer to a request that was not delivered, then throws the learner-facing message.
     *
     * @param \stdClass $row
     * @param int $status
     * @param string $body
     * @param array $headers
     * @throws \moodle_exception always
     */
    public static function not_delivered(\stdClass $row, int $status, string $body, array $headers = []): void {
        $outcome = remote::outcome($status, $body);
        if ($outcome === 'gone') {
            self::expired($row);
            throw new \moodle_exception('remotegone', 'mod_aispeakwrite');
        }
        if ($outcome === 'refused') {
            self::failed($row);
        } else {
            global $DB;
            $DB->set_field(self::TABLE, 'timemodified', time(), ['id' => $row->id]);
        }
        remote::fail($status, $body, $headers);
    }

    /**
     * Credits spent in an activity (or the whole site) since a time.
     *
     * @param int $since
     * @param int $aid 0 for all activities
     * @return array ['credits' => int, 'mark' => int, 'converse' => int]
     */
    public static function usage(int $since, int $aid = 0): array {
        global $DB;
        $where = "state = 'complete' AND timemodified >= :since";
        $params = ['since' => $since];
        if ($aid) {
            $where .= ' AND aispeakwriteid = :aid';
            $params['aid'] = $aid;
        }
        $rows = $DB->get_records_sql(
            "SELECT kind, COUNT(1) AS n, SUM(credits) AS credits
                                        FROM {" . self::TABLE . "}
                                       WHERE $where
                                    GROUP BY kind",
            $params
        );
        $out = ['credits' => 0, 'mark' => 0, 'converse' => 0, 'test' => 0];
        foreach ($rows as $row) {
            $out['credits'] += (int)$row->credits;
            $out[$row->kind] = (int)$row->n;
        }
        return $out;
    }
}
