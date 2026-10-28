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

/**
 * Protected AES-128 decryption key endpoint for HLS playback.
 *
 * @package    local_securevideolibrary
 * @copyright  2025 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_MOODLE_COOKIES', false); // Requires Moodle session cookies.
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

// Enforce authentication.
require_login();

$mediaid = required_param('mediaid', PARAM_INT);
$token   = required_param('token', PARAM_ALPHANUM);

$headertoken = $_SERVER['HTTP_X_MOODLE_PLAYER_TOKEN'] ?? '';

// Verify single-use token and player handshake.
if (!\local_securevideolibrary\token_manager::verify_and_consume_token($mediaid, $token, $headertoken)) {
    header('HTTP/1.1 403 Forbidden');
    header('Content-Type: text/plain');
    die('Forbidden: Missing, expired, or invalid playback authorization token.');
}

$media = $DB->get_record('local_securevideolibrary_media', ['id' => $mediaid]);
if (!$media || $media->status !== LOCAL_SECUREVIDEOLIBRARY_STATUS_READY) {
    send_header_404();
    die('Media not found or not ready.');
}

// Enforce access permission.
if (!local_securevideolibrary_can_access_media($media)) {
    header('HTTP/1.1 403 Forbidden');
    die('Forbidden: You do not have permission to access the decryption key for this video.');
}

if (empty($media->encryptionkey)) {
    send_header_404();
    die('Encryption key is not set.');
}

$rawkey = hex2bin($media->encryptionkey);
if ($rawkey === false || strlen($rawkey) !== 16) {
    header('HTTP/1.1 500 Internal Server Error');
    die('Invalid encryption key length.');
}

// Output raw 16-byte binary key with strict no-cache headers.
header('Content-Type: application/octet-stream');
header('Content-Length: 16');
header('Cache-Control: no-cache, no-store, must-revalidate, private');
header('Pragma: no-cache');
header('Expires: 0');
echo $rawkey;
exit;
