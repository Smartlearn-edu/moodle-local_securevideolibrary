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
 * Protected streaming endpoint for HLS playlists (.m3u8), segments (.ts), and thumbnails (.jpg).
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

$mediaid  = required_param('mediaid', PARAM_INT);
$filename = required_param('file', PARAM_FILE);

$media = $DB->get_record('local_securevideolibrary_media', ['id' => $mediaid]);
if (!$media || $media->status !== LOCAL_SECUREVIDEOLIBRARY_STATUS_READY) {
    send_header_404();
    die('Media not found or not ready.');
}

// Enforce capability and access rules.
if (!local_securevideolibrary_can_access_media($media)) {
    header('HTTP/1.1 403 Forbidden');
    die('Forbidden: You do not have permission to access this media.');
}

// Retrieve sanitized filesystem path.
$filepath = \local_securevideolibrary\storage::get_file_path($mediaid, $filename);
if (!$filepath || !file_exists($filepath)) {
    send_header_404();
    die('Requested file does not exist.');
}

$ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));

// Playlist handling: rewrite relative paths to absolute serve.php URLs for reliable client playback.
if ($ext === 'm3u8') {
    $content = file_get_contents($filepath);
    if ($content === false) {
        send_header_404();
        die('Could not read playlist file.');
    }

    // Rewrite lines pointing to .ts segments or variant .m3u8 playlists.
    $rewritten = preg_replace_callback(
        '/^(?!#)([a-zA-Z0-9_\-]+\.(?:ts|m3u8))$/m',
        function ($matches) use ($mediaid) {
            return (new moodle_url('/local/securevideolibrary/serve.php', [
                'mediaid' => $mediaid,
                'file'    => $matches[1],
            ]))->out(false);
        },
        $content
    );

    header('Content-Type: application/vnd.apple.mpegurl; charset=utf-8');
    header('Content-Length: ' . strlen($rewritten));
    header('Cache-Control: private, no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo $rewritten;
    exit;
}

// Encrypted video segment handling (.ts).
if ($ext === 'ts') {
    $filesize = filesize($filepath);
    header('Content-Type: video/mp2t');
    header('Content-Length: ' . $filesize);
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, max-age=86400'); // Cache encrypted segments in browser memory.

    // Stream the binary segment directly.
    readfile($filepath);
    exit;
}

// Thumbnail image handling.
if ($ext === 'jpg' || $ext === 'jpeg') {
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($filepath));
    header('Cache-Control: private, max-age=86400');
    readfile($filepath);
    exit;
}

if ($ext === 'png') {
    header('Content-Type: image/png');
    header('Content-Length: ' . filesize($filepath));
    header('Cache-Control: private, max-age=86400');
    readfile($filepath);
    exit;
}

send_header_404();
die('Unsupported file type.');
