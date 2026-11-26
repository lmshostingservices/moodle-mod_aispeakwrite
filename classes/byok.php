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

namespace mod_aispeakwrite;

use mod_aispeakwrite\local\byok\engine;

/**
 * Bring your own AI: markings made on the site's own AI instead of LMS Labs credits.
 *
 * When Text is set to Moodle AI or the site's own key in AI Grader Central Config, each marking runs here, from this
 * Moodle site straight to the AI provider, with the LMS Labs service's prompts and checks, and costs no credits.
 * Conversation practice is not part of this: LMS Labs does not offer it yet, with or without credits.
 *
 * @package    mod_aispeakwrite
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class byok {
    /** @var string This plugin, as AI Grader Central Config knows it. */
    const COMPONENT = 'mod_aispeakwrite';

    /**
     * Whether markings run on the site's own AI now.
     *
     * @return bool
     */
    public static function uses_own_ai(): bool {
        return class_exists('\local_aiconfig\byok\ai') && \local_aiconfig\byok\ai::uses_own_ai(self::COMPONENT, 'text');
    }

    /**
     * Mark one answer on the site's own AI, answering as the LMS Labs mark route does.
     *
     * @param array $body The request body the plugin sends to LMS Labs.
     * @return array [status, body (JSON), info, headers] as remote::request() returns them
     */
    public static function mark(array $body): array {
        engine::$text = function (string $system, string $user, int $maxtokens, float $temperature, array $schema): string {
            \core_php_time_limit::raise(300);
            return \local_aiconfig\byok\ai::text(self::COMPONENT, $system, $user, [
                'maxtokens' => $maxtokens, 'temperature' => $temperature, 'json' => true, 'timeout' => 150,
                'schema' => $schema,
            ]);
        };
        [$status, $answer] = engine::mark(json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($status === 502) {
            // Nothing is charged on the site's own AI, so the learner can simply try again.
            debugging('Bring your own AI: the marking failed: ' . $answer['error']['reason'], DEBUG_DEVELOPER);
            $status = 422;
            $answer = ['error' => ['code' => 'BYOK_FAILED', 'message' => 'byok failed']];
        }
        unset($answer['error']['reason']);
        return [$status, json_encode($answer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), [], []];
    }
}
