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

$hasmstoken = !empty($_GET['mstoken']);
define('NO_MOODLE_COOKIES', $hasmstoken);
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

\local_securevideolibrary\token_manager::handle_cors_and_preflight();

$mediaid = required_param('mediaid', PARAM_INT);
$token   = required_param('token', PARAM_ALPHANUM);
$cmid    = optional_param('cmid', 0, PARAM_INT);
$mstoken = optional_param('mstoken', '', PARAM_ALPHANUM);

$userid = 0;
if (!empty($mstoken)) {
    $userid = \local_securevideolibrary\token_manager::verify_mobile_stream_token($mstoken, $mediaid, $cmid);
    if ($userid <= 0) {
        header('HTTP/1.1 403 Forbidden');
        header('Content-Type: text/plain');
        die('Forbidden: Invalid or expired mobile stream token.');
    }
} else {
    require_login();
    $userid = (int)$USER->id;
}

$headertoken = $_SERVER['HTTP_X_MOODLE_PLAYER_TOKEN'] ?? '';

// Verify single-use token and player handshake.
if (!\local_securevideolibrary\token_manager::verify_and_consume_token($mediaid, $token, $headertoken, !empty($mstoken))) {
    header('HTTP/1.1 403 Forbidden');
    header('Content-Type: text/plain');
    die('Forbidden: Missing, expired, or invalid playback authorization token.');
}

$media = $DB->get_record('local_securevideolibrary_media', ['id' => $mediaid]);
if (!$media || $media->status !== LOCAL_SECUREVIDEOLIBRARY_STATUS_READY) {
    send_header_404();
    die('Media not found or not ready.');
}

// Enforce access permission (verified mstoken already cryptographically binds userid, mediaid, and cmid).
if (empty($mstoken) && !local_securevideolibrary_can_access_media($media, $userid, $cmid)) {
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
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
echo $rawkey;
exit;
