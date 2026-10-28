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
 * Ephemeral, single-use token manager for anti-downloader key protection.
 *
 * @package    local_securevideolibrary
 * @copyright  2025 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class token_manager {
    /**
     * Generate an ephemeral, single-use token for key delivery.
     *
     * @param int $mediaid Media ID.
     * @return string 32-character hexadecimal token.
     */
    public static function generate_key_token(int $mediaid): string {
        global $SESSION;

        if (!isset($SESSION->securevideo_key_tokens) || !is_array($SESSION->securevideo_key_tokens)) {
            $SESSION->securevideo_key_tokens = [];
        }

        // Clean up expired tokens older than 60 seconds.
        $now = time();
        foreach ($SESSION->securevideo_key_tokens as $t => $info) {
            if ($now - $info['time'] > 60) {
                unset($SESSION->securevideo_key_tokens[$t]);
            }
        }

        $token = bin2hex(random_bytes(16));
        $SESSION->securevideo_key_tokens[$token] = [
            'mediaid' => $mediaid,
            'time'    => $now,
            'used'    => false,
        ];

        return $token;
    }

    /**
     * Verify and immediately consume a single-use key token.
     * Enforces that custom handshake header matches and token is unconsumed.
     *
     * @param int $mediaid Media ID.
     * @param string $token Token from query parameter.
     * @param string $headertoken Token from X-Moodle-Player-Token header.
     * @return bool True if valid and consumed, false otherwise.
     */
    public static function verify_and_consume_token(int $mediaid, string $token, string $headertoken): bool {
        global $SESSION;

        if (empty($token) || empty($headertoken)) {
            return false;
        }

        // The custom handshake header must strictly match the token.
        if (!hash_equals($token, $headertoken)) {
            return false;
        }

        if (!isset($SESSION->securevideo_key_tokens) || !is_array($SESSION->securevideo_key_tokens)) {
            return false;
        }

        if (!isset($SESSION->securevideo_key_tokens[$token])) {
            return false;
        }

        $data = $SESSION->securevideo_key_tokens[$token];

        // Ensure token belongs to this media item.
        if ((int)$data['mediaid'] !== $mediaid) {
            return false;
        }

        // Token lifetime: maximum 45 seconds from generation to playback start.
        if (time() - $data['time'] > 45) {
            unset($SESSION->securevideo_key_tokens[$token]);
            return false;
        }

        // Ensure single-use (prevent replay attacks by extensions).
        if (!empty($data['used'])) {
            unset($SESSION->securevideo_key_tokens[$token]);
            return false;
        }

        // Consume and invalidate immediately.
        $SESSION->securevideo_key_tokens[$token]['used'] = true;
        unset($SESSION->securevideo_key_tokens[$token]);

        return true;
    }
}
