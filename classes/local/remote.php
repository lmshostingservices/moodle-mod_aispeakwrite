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
 * Server-to-server transport to the fixed LMS Labs host.
 *
 * Credentials are sent only in request headers, only to lms-labs.com, and never reach JavaScript, templates or logs.
 * Redirects are never followed. Nothing is retried here: a paid request is only ever sent again by the learner's own
 * click, with the same Idempotency-Key.
 *
 * @package    mod_aispeakwrite
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class remote {
    /** @var string Fixed LMS Labs host. */
    public const HOST = 'https://lms-labs.com';

    /** @var string Marks one writing or speaking answer (1 credit when delivered). */
    public const MARK = '/api/moodle/ai-speak-write/mark';

    /** @var string The AI partner's next turn in a speaking conversation. */
    public const CONVERSE = '/api/moodle/ai-speak-write/converse';

    /** @var string Credit balance. */
    public const CREDITS = '/api/credits';

    /** @var string Route that switches a plugin on for a site (as Essay Guard uses). */
    public const UNLOCK = '/api/plugin-unlock';

    /** @var string This plugin's id at LMS Labs. */
    public const PLUGINID = 'aispeakwrite';

    /** @var int Seconds to wait before trying to switch the plugin on again after a failed try. */
    public const UNLOCKRETRY = 600;

    /** @var string[] Error codes that leave the outcome unknown (the same key must be asked again). */
    public const UNCERTAIN = ['SETTLEMENT_UNCONFIRMED', 'DEADLINE_EXCEEDED'];

    /** @var string[] Documented 5xx codes used when the provider failed before anything was charged. */
    public const PROVIDER_REFUSED = ['PROVIDER_UNAVAILABLE', 'PROVIDER_FAILED', 'PROVIDER_RATE_LIMITED',
        'INVALID_PROVIDER_RESULT', 'TARIFF_NOT_APPROVED'];

    /**
     * @var callable|null Replacement transport for unit tests: fn($url, $headers, $body) => [status, body, info, headers].
     */
    public static $transport = null;

    /**
     * Sends one request.
     *
     * @param string $path route on the fixed host
     * @param array|null $body JSON body (POST), or null for GET
     * @param string $key Idempotency-Key (POST)
     * @param int $timeout seconds to wait for the answer
     * @return array [status, body, info, headers (lower-case names)]
     * @throws \moodle_exception remoteuncertain when no answer arrived
     */
    public static function request(string $path, ?array $body = null, string $key = '', int $timeout = 60): array {
        $answer = self::send($path, $body, $key, $timeout);
        // LMS Labs refuses a site that hasn't switched this plugin on yet (403 NO_ENTITLEMENT). Switching it on is
        // free for this plugin, so do it once and send the same request again, with the same key and body. The
        // refusal came before anything was charged or stored, so resending is safe.
        if (in_array($path, [self::MARK, self::CONVERSE], true) && self::needs_unlock($answer[0], $answer[1])) {
            if (self::unlock()) {
                $answer = self::send($path, $body, $key, $timeout);
            }
        }
        return $answer;
    }

    /**
     * Whether an answer is LMS Labs saying this plugin isn't switched on for the site.
     *
     * @param int $status
     * @param string $body
     * @return bool
     */
    public static function needs_unlock(int $status, string $body): bool {
        return $status === 403 && self::error($status, $body) !== 'INSUFFICIENT_CREDITS';
    }

    /**
     * Switches this plugin on for the site at LMS Labs (free for AI Advent Speaking and Writing).
     *
     * Tried at most once every ten minutes after a failure, so a site that can't be switched on doesn't send an
     * extra request with every marking.
     *
     * @return bool true when LMS Labs says the plugin is now switched on
     */
    public static function unlock(): bool {
        $last = (int)get_config('mod_aispeakwrite', 'unlocktried');
        if ($last > 0 && time() - $last < self::UNLOCKRETRY) {
            return false;
        }
        set_config('unlocktried', time(), 'mod_aispeakwrite');
        $pair = credentials::resolve();
        $fields = ['pluginId' => self::PLUGINID, 'siteId' => $pair['siteid'], 'apiKey' => $pair['apikey']];
        $json = json_encode($fields, JSON_UNESCAPED_SLASHES);
        $headers = ['X-Site-ID: ' . $pair['siteid'], 'X-API-Key: ' . $pair['apikey'],
            'Content-Type: application/json', 'Accept: application/json'];
        try {
            [$status, $response] = self::transmit(self::HOST . self::UNLOCK, $headers, $json, 15);
        } catch (\moodle_exception $e) {
            return false;
        }
        $data = json_decode($response, true);
        if ($status !== 200 || !is_array($data) || empty($data['unlocked'])) {
            debugging('[mod_aispeakwrite] LMS Labs did not switch the plugin on (HTTP ' . $status . ').', DEBUG_DEVELOPER);
            return false;
        }
        set_config('unlocked', time(), 'mod_aispeakwrite');
        unset_config('unlocktried', 'mod_aispeakwrite');
        return true;
    }

    /**
     * Sends one request to a route on the fixed host, without the switch-on step.
     *
     * @param string $path
     * @param array|null $body
     * @param string $key
     * @param int $timeout
     * @return array [status, body, info, headers (lower-case names)]
     * @throws \moodle_exception remoteuncertain when no answer arrived
     */
    protected static function send(string $path, ?array $body, string $key, int $timeout): array {
        $pair = credentials::resolve();
        $headers = ['X-Site-ID: ' . $pair['siteid'], 'X-API-Key: ' . $pair['apikey'], 'Accept: application/json'];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Idempotency-Key: ' . $key;
        }
        $json = $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return self::transmit(self::HOST . $path, $headers, $json, $timeout);
    }

    /**
     * Sends bytes over HTTPS (or the test transport).
     *
     * @param string $url
     * @param array $headers
     * @param string|null $json POST body, or null for GET
     * @param int $timeout
     * @return array [status, body, info, headers (lower-case names)]
     * @throws \moodle_exception remoteuncertain when no answer arrived
     */
    protected static function transmit(string $url, array $headers, ?string $json, int $timeout): array {
        global $CFG;
        if (self::$transport !== null) {
            $answer = (self::$transport)($url, $headers, $json);
            return [(int)$answer[0], (string)$answer[1], $answer[2] ?? [], array_change_key_case($answer[3] ?? [])];
        }
        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl();
        $curl->setHeader($headers);
        // Never follow a redirect: the credential headers would be sent on to the new address.
        $opts = ['CURLOPT_CONNECTTIMEOUT' => 5, 'CURLOPT_TIMEOUT' => $timeout, 'CURLOPT_FOLLOWLOCATION' => 0];
        $response = $json === null ? $curl->get($url, [], $opts) : $curl->post($url, $json, $opts);
        if ($curl->get_errno()) {
            throw new \moodle_exception('remoteuncertain', 'mod_aispeakwrite');
        }
        $info = $curl->get_info();
        return [(int)($info['http_code'] ?? 0), (string)$response, $info,
            array_change_key_case((array)$curl->getResponse())];
    }

    /**
     * The upper-case error code of an answer, or REMOTE_ERROR.
     *
     * @param int $status
     * @param string $body
     * @return string
     */
    public static function error(int $status, string $body): string {
        $data = json_decode($body, true);
        $code = is_array($data) ? ($data['error']['code'] ?? $data['error'] ?? 'REMOTE_ERROR') : 'REMOTE_ERROR';
        return is_string($code) && preg_match('/^[A-Z_]{2,60}$/', $code) ? $code : 'REMOTE_ERROR';
    }

    /**
     * What a non-200 answer means for a stored request.
     *
     * @param int $status
     * @param string $body
     * @return string pending (ask again later, same key), uncertain (same key), gone, or refused (nothing charged)
     */
    public static function outcome(int $status, string $body): string {
        $code = self::error($status, $body);
        if ($status === 202) {
            return 'pending';
        }
        if ($status === 410) {
            return 'gone';
        }
        // An unexpected server error is never taken as "not charged": only documented provider refusals are a no.
        if (
            $status === 0 || in_array($code, self::UNCERTAIN, true) ||
                ($status >= 500 && !in_array($code, self::PROVIDER_REFUSED, true))
        ) {
            return 'uncertain';
        }
        return 'refused';
    }

    /**
     * Throws the learner-facing exception for a non-200 answer (the caller has already updated the stored request).
     *
     * @param int $status
     * @param string $body
     * @param array $headers
     * @throws \moodle_exception always
     */
    public static function fail(int $status, string $body, array $headers = []): void {
        $outcome = self::outcome($status, $body);
        if ($outcome === 'pending') {
            $wait = max(1, min(60, (int)($headers['retry-after'] ?? 5)));
            throw new \moodle_exception('remotepending', 'mod_aispeakwrite', '', $wait);
        }
        if ($outcome === 'uncertain') {
            throw new \moodle_exception('remoteuncertain', 'mod_aispeakwrite');
        }
        $code = $status === 404 ? 'NOT_LIVE' : self::error($status, $body);
        if ($status === 402) {
            $code = 'INSUFFICIENT_CREDITS';
        } else if ($status === 401) {
            $code = 'INVALID_CREDENTIALS';
        } else if ($status === 403 && $code !== 'INSUFFICIENT_CREDITS') {
            $code = 'NO_ENTITLEMENT';
        } else if ($status === 429) {
            $code = 'RATE_LIMITED';
        } else if ($status === 409) {
            $code = 'IDEMPOTENCY_CONFLICT';
        }
        $known = ['INSUFFICIENT_CREDITS', 'NO_ENTITLEMENT', 'INVALID_CREDENTIALS', 'RATE_LIMITED', 'NOT_LIVE',
            'IDEMPOTENCY_CONFLICT'];
        $key = in_array($code, $known, true) ? 'remoterefused_' . strtolower($code) : 'remoteerror';
        throw new \moodle_exception($key, 'mod_aispeakwrite', '', $code);
    }

    /**
     * Reads the site's credit balance.
     *
     * @return array|null ['unlimited' => bool, 'credits' => int] or null when unavailable
     */
    public static function balance(): ?array {
        if (credentials::find() === null) {
            return null;
        }
        $pair = credentials::resolve();
        try {
            [$status, $body] = self::request(self::CREDITS . '?siteId=' . rawurlencode($pair['siteid']), null, '', 10);
        } catch (\moodle_exception $e) {
            return null;
        }
        if ($status !== 200) {
            return null;
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return null;
        }
        if (!empty($data['isUnlimited'])) {
            return ['unlimited' => true, 'credits' => -1];
        }
        foreach (['creditsRaw', 'credits', 'creditsBalance', 'balance'] as $field) {
            if (isset($data[$field]) && is_numeric($data[$field])) {
                return ['unlimited' => false, 'credits' => (int)$data[$field]];
            }
        }
        return null;
    }
}
