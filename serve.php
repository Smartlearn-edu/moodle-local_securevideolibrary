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

$hasmstoken = !empty($_GET['mstoken']);
define('NO_MOODLE_COOKIES', $hasmstoken);
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

\local_securevideolibrary\token_manager::handle_cors_and_preflight();

$filename = required_param('file', PARAM_FILE);
$mediaid  = optional_param('mediaid', 0, PARAM_INT);
$cmid     = optional_param('cmid', 0, PARAM_INT);
$mstoken  = optional_param('mstoken', '', PARAM_ALPHANUM);

// 1. Direct serve of local bundled HLS.js for offline and mobile caching (zero external CDNs).
if ($filename === 'hls.js') {
    $hlspath = __DIR__ . '/amd/build/hls-lazy.min.js';
    if (!file_exists($hlspath)) {
        $hlspath = __DIR__ . '/amd/src/hls-lazy.js';
    }
    if (file_exists($hlspath)) {
        header('Content-Type: application/javascript; charset=utf-8');
        header('Content-Length: ' . filesize($hlspath));
        header('Cache-Control: public, max-age=604800, immutable');
        readfile($hlspath);
        exit;
    }
    send_header_404();
    die('HLS library bundle not found.');
}

if ($mediaid <= 0) {
    send_header_404();
    die('Invalid media ID.');
}

$userid = 0;
if (!empty($mstoken)) {
    $userid = \local_securevideolibrary\token_manager::verify_mobile_stream_token($mstoken, $mediaid, $cmid);
    if ($userid <= 0) {
        header('HTTP/1.1 403 Forbidden');
        die('Forbidden: Invalid or expired mobile stream token.');
    }
} else {
    require_login();
    $userid = (int)$USER->id;
}

$media = $DB->get_record('local_securevideolibrary_media', ['id' => $mediaid]);
if (!$media || $media->status !== LOCAL_SECUREVIDEOLIBRARY_STATUS_READY) {
    send_header_404();
    die('Media not found or not ready.');
}

// Enforce capability and access rules.
if (!local_securevideolibrary_can_access_media($media, $userid, $cmid)) {
    header('HTTP/1.1 403 Forbidden');
    die('Forbidden: You do not have permission to access this media.');
}

// Release session lock for non-playlist requests so segments stream concurrently without blocking.
if (empty($mstoken) && $filename !== 'playlist.m3u8' && substr($filename, -5) !== '.m3u8') {
    \core\session\manager::write_close();
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

    // Inject ephemeral one-time token into the #EXT-X-KEY URI to prevent downloader extensions.
    $token = \local_securevideolibrary\token_manager::generate_key_token($mediaid);
    $keyparams = [
        'mediaid' => $mediaid,
        'token'   => $token,
    ];
    if ($cmid > 0) {
        $keyparams['cmid'] = $cmid;
    }
    if (!empty($mstoken)) {
        $keyparams['mstoken'] = $mstoken;
    }
    $keyurl = (new moodle_url('/local/securevideolibrary/key.php', $keyparams))->out(false);

    $content = preg_replace(
        '/#EXT-X-KEY:METHOD=AES-128,URI="[^"]+",IV=(0x[a-fA-F0-9]+)/',
        '#EXT-X-KEY:METHOD=AES-128,URI="' . $keyurl . '",IV=$1',
        $content
    );

    // Rewrite lines pointing to .ts segments or variant .m3u8 playlists.
    $rewritten = preg_replace_callback(
        '/^(?!#)([a-zA-Z0-9_\-]+\.(?:ts|m3u8))$/m',
        function ($matches) use ($mediaid, $cmid, $mstoken) {
            $params = [
                'mediaid' => $mediaid,
                'file'    => $matches[1],
            ];
            if ($cmid > 0) {
                $params['cmid'] = $cmid;
            }
            if (!empty($mstoken)) {
                $params['mstoken'] = $mstoken;
            }
            return (new moodle_url('/local/securevideolibrary/serve.php', $params))->out(false);
        },
        $content
    );

    header('Content-Type: application/vnd.apple.mpegurl; charset=utf-8');
    header('Content-Length: ' . strlen($rewritten));
    header('Cache-Control: private, no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    if (empty($mstoken)) {
        \core\session\manager::write_close();
    }
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

if ($ext === 'vtt') {
    header('Content-Type: text/vtt; charset=utf-8');
    header('Content-Length: ' . filesize($filepath));
    header('Cache-Control: private, max-age=86400');
    readfile($filepath);
    exit;
}

send_header_404();
die('Unsupported file type.');
