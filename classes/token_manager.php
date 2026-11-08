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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_securevideolibrary;

/**
 * Ephemeral, single-use token manager for anti-downloader key protection and mobile streaming.
 *
 * @package    local_securevideolibrary
 * @copyright  2025 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class token_manager {
    /**
     * Get the site-specific cryptographic signing secret.
     *
     * @return string Signing secret key.
     */
    private static function get_signing_secret(): string {
        global $CFG;
        $salt = $CFG->passwordsaltmain ?? '';
        return hash('sha256', 'local_securevideolibrary_key_' . $salt . '_' . get_site_identifier());
    }

    /**
     * Emit CORS headers for Moodle Mobile App WebView origins and handle OPTIONS preflight.
     *
     * @return void
     */
    public static function handle_cors_and_preflight(): void {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if (!empty($origin)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Credentials: true');
            header('Vary: Origin');
        } else {
            header('Access-Control-Allow-Origin: *');
        }
        header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
        header('Access-Control-Allow-Headers: X-Moodle-Player-Token, X-Requested-With, Content-Type, Authorization, Range');
        header('Access-Control-Expose-Headers: Content-Length, Content-Range, Accept-Ranges');

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            header('HTTP/1.1 204 No Content');
            exit;
        }
    }

    /**
     * Generate a signed stateless mobile stream token for Moodle Mobile App playback.
     *
     * @param int $userid Authenticated user ID.
     * @param int $mediaid Media record ID.
     * @param int $cmid Course module ID.
     * @param int $ttl Token lifetime in seconds (default 4 hours).
     * @return string Alphanumeric signed token.
     */
    public static function generate_mobile_stream_token(
        int $userid,
        int $mediaid,
        int $cmid = 0,
        int $ttl = 14400
    ): string {
        $exp = time() + $ttl;
        $payload = sprintf('%xZ%xZ%xZ%x', $userid, $mediaid, $cmid, $exp);
        $sig = substr(hash_hmac('sha256', $payload, self::get_signing_secret()), 0, 24);
        return $payload . 'Z' . $sig;
    }

    /**
     * Verify a signed mobile stream token and return the authenticated user ID.
     *
     * @param string $mstoken Mobile stream token.
     * @param int $mediaid Expected media ID.
     * @param int $cmid Expected course module ID.
     * @return int Authenticated user ID, or 0 if invalid/expired.
     */
    public static function verify_mobile_stream_token(string $mstoken, int $mediaid, int $cmid = 0): int {
        if (empty($mstoken)) {
            return 0;
        }
        $parts = explode('Z', $mstoken);
        if (count($parts) !== 5) {
            return 0;
        }
        [$uidhex, $midhex, $cmidhex, $exphex, $sig] = $parts;
        $payload = "{$uidhex}Z{$midhex}Z{$cmidhex}Z{$exphex}";
        $expectedsig = substr(hash_hmac('sha256', $payload, self::get_signing_secret()), 0, 24);
        if (!hash_equals($expectedsig, $sig)) {
            return 0;
        }

        $userid = (int)hexdec($uidhex);
        $tokenmediaid = (int)hexdec($midhex);
        $tokencmid = (int)hexdec($cmidhex);
        $exp = (int)hexdec($exphex);

        if ($userid <= 0 || $tokenmediaid !== $mediaid || $tokencmid !== $cmid || time() > $exp) {
            return 0;
        }

        return $userid;
    }

    /**
     * Generate an ephemeral, single-use token for key delivery.
     *
     * @param int $mediaid Media ID.
     * @return string 32-character hexadecimal token.
     */
    public static function generate_key_token(int $mediaid): string {
        global $SESSION;

        $now = time();
        $nonce = bin2hex(random_bytes(4));
        $tshex = sprintf('%08x', $now);
        $sig = substr(hash_hmac('sha256', "{$mediaid}:{$tshex}:{$nonce}", self::get_signing_secret()), 0, 16);
        $token = $nonce . $tshex . $sig;

        if (isset($SESSION) && is_object($SESSION)) {
            if (!isset($SESSION->securevideo_key_tokens) || !is_array($SESSION->securevideo_key_tokens)) {
                $SESSION->securevideo_key_tokens = [];
            }

            // Clean up expired tokens older than 60 seconds.
            foreach ($SESSION->securevideo_key_tokens as $t => $info) {
                if ($now - $info['time'] > 60) {
                    unset($SESSION->securevideo_key_tokens[$t]);
                }
            }

            $SESSION->securevideo_key_tokens[$token] = [
                'mediaid' => $mediaid,
                'time'    => $now,
                'used'    => false,
            ];
        }

        return $token;
    }

    /**
     * Verify and immediately consume a single-use key token.
     * Enforces that custom handshake header matches and token is unconsumed.
     *
     * @param int $mediaid Media ID.
     * @param string $token Token from query parameter.
     * @param string $headertoken Token from X-Moodle-Player-Token header.
     * @param bool $allowstateless True if authenticated via signed mobile stream token (iOS native AVPlayer).
     * @return bool True if valid and consumed, false otherwise.
     */
    public static function verify_and_consume_token(
        int $mediaid,
        string $token,
        string $headertoken,
        bool $allowstateless = false
    ): bool {
        global $SESSION;

        if (empty($token) || strlen($token) !== 32) {
            return false;
        }

        // In standard web mode (or when header is sent by hls.js), header must strictly match token.
        if (!$allowstateless || !empty($headertoken)) {
            if (empty($headertoken) || !hash_equals($token, $headertoken)) {
                return false;
            }
        }

        // 1. Check session store if present.
        if (isset($SESSION->securevideo_key_tokens) && is_array($SESSION->securevideo_key_tokens)) {
            if (isset($SESSION->securevideo_key_tokens[$token])) {
                $data = $SESSION->securevideo_key_tokens[$token];
                if ((int)$data['mediaid'] !== $mediaid) {
                    return false;
                }
                if (time() - $data['time'] > 45) {
                    unset($SESSION->securevideo_key_tokens[$token]);
                    return false;
                }
                if (!empty($data['used'])) {
                    unset($SESSION->securevideo_key_tokens[$token]);
                    return false;
                }
                $SESSION->securevideo_key_tokens[$token]['used'] = true;
                unset($SESSION->securevideo_key_tokens[$token]);
                return true;
            }
        }

        // 2. Fallback for stateless mobile streaming (where NO_MOODLE_COOKIES is active).
        if (!$allowstateless) {
            return false;
        }

        $nonce = substr($token, 0, 8);
        $tshex = substr($token, 8, 8);
        $sig = substr($token, 16, 16);
        $expectedsig = substr(hash_hmac('sha256', "{$mediaid}:{$tshex}:{$nonce}", self::get_signing_secret()), 0, 16);

        if (!hash_equals($expectedsig, $sig)) {
            return false;
        }

        $issued = (int)hexdec($tshex);
        if (time() - $issued > 60) {
            return false;
        }

        return true;
    }
}
