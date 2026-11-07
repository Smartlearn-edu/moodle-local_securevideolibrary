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

use moodle_exception;
use stdClass;
use ZipArchive;

/**
 * Importer for pre-transcoded and encrypted desktop video packages (.svideo.zip).
 *
 * @package    local_securevideolibrary
 * @copyright  2025 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class package_importer {

    /**
     * Import a pre-transcoded .zip package into the secure video library.
     *
     * @param string $zipfilepath Path to the zip package file on disk.
     * @param array $overrides User-provided metadata overrides (title, description, visibility, courseid, etc.).
     * @return int New media ID.
     * @throws moodle_exception
     */
    public static function import_package(string $zipfilepath, array $overrides = []): int {
        global $DB, $USER;

        if (!file_exists($zipfilepath) || !is_readable($zipfilepath)) {
            throw new moodle_exception('err_filenotfound', 'local_securevideolibrary');
        }

        $zip = new ZipArchive();
        $res = $zip->open($zipfilepath);
        if ($res !== true) {
            throw new moodle_exception('err_invalidpackage', 'local_securevideolibrary');
        }

        $manifestfound = false;
        $playlistfound = false;
        $segmentcount  = 0;
        $thumbnailfound = false;

        // 1. Pre-scan ZIP entries for integrity & security rules.
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $filename = basename($stat['name']);

            // STRICT SECURITY RULE: Raw key files must never be bundled into the package.
            if ($filename === 'enc.key' || $filename === 'keyinfo.txt') {
                $zip->close();
                throw new moodle_exception('err_securityviolation', 'local_securevideolibrary');
            }

            if ($filename === 'manifest.json') {
                $manifestfound = true;
            } else if ($filename === 'playlist.m3u8') {
                $playlistfound = true;
            } else if (preg_match('/\.ts$/i', $filename)) {
                $segmentcount++;
            } else if ($filename === 'thumbnail.jpg') {
                $thumbnailfound = true;
            }
        }

        if (!$manifestfound) {
            $zip->close();
            throw new moodle_exception('err_missingmanifest', 'local_securevideolibrary');
        }

        if (!$playlistfound) {
            $zip->close();
            throw new moodle_exception('err_missingplaylist', 'local_securevideolibrary');
        }

        if ($segmentcount === 0) {
            $zip->close();
            throw new moodle_exception('err_missingsegments', 'local_securevideolibrary');
        }

        // 2. Read and parse manifest.json.
        $manifestcontent = $zip->getFromName('manifest.json');
        if ($manifestcontent === false) {
            $zip->close();
            throw new moodle_exception('err_missingmanifest', 'local_securevideolibrary');
        }

        $manifest = json_decode($manifestcontent);
        if (!$manifest || !isset($manifest->format) || $manifest->format !== 'local_securevideolibrary_package') {
            $zip->close();
            throw new moodle_exception('err_invalidpackage', 'local_securevideolibrary');
        }

        // Validate encryption credentials (must be exact 32 hex characters = 16 bytes).
        if (
            empty($manifest->encryptionkey) ||
            !preg_match('/^[a-fA-F0-9]{32}$/', $manifest->encryptionkey) ||
            empty($manifest->encryptioniv) ||
            !preg_match('/^[a-fA-F0-9]{32}$/', $manifest->encryptioniv)
        ) {
            $zip->close();
            throw new moodle_exception('err_invalidcredentials', 'local_securevideolibrary');
        }

        $title = !empty($overrides['title']) ? trim($overrides['title']) : ($manifest->title ?? 'Imported Video');
        $description = $overrides['description'] ?? ($manifest->description ?? null);
        $uploaderid = !empty($overrides['uploaderid']) ? (int)$overrides['uploaderid'] : (int)$USER->id;
        $courseid = !empty($overrides['courseid']) ? (int)$overrides['courseid'] : 0;
        $contextid = !empty($overrides['contextid']) ? (int)$overrides['contextid'] : \context_system::instance()->id;
        $visibility = !empty($overrides['visibility']) ? $overrides['visibility'] : media::VISIBILITY_PRIVATE;

        $bitrateprofile = !empty($manifest->bitrateprofile) && in_array($manifest->bitrateprofile, ['single', 'multi'])
            ? $manifest->bitrateprofile
            : 'single';

        $compressionprofile = !empty($manifest->compressionprofile) &&
            in_array($manifest->compressionprofile, ['datasaver', 'balanced', 'high'])
            ? $manifest->compressionprofile
            : 'balanced';

        $duration = !empty($manifest->duration) ? (int)$manifest->duration : 0;

        // 3. Insert database record.
        $media = new stdClass();
        $media->title              = $title;
        $media->description        = $description;
        $media->duration           = $duration;
        $media->filesize           = 0;
        $media->uploaderid         = $uploaderid;
        $media->courseid           = $courseid;
        $media->contextid          = $contextid;
        $media->visibility         = $visibility;
        $media->bitrateprofile     = $bitrateprofile;
        $media->compressionprofile = $compressionprofile;
        $media->status             = media::STATUS_READY;
        $media->errormessage       = null;
        $media->encryptionkey      = strtolower($manifest->encryptionkey);
        $media->encryptioniv       = strtolower($manifest->encryptioniv);
        $media->timecreated        = time();
        $media->timemodified       = time();

        $mediaid = $DB->insert_record('local_securevideolibrary_media', $media);

        // 4. Extract streaming files directly into dataroot/securevideo/{mediaid}/.
        $targetdir = storage::get_media_dir($mediaid, true);

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryname = $zip->getNameIndex($i);
            $filename = basename($entryname);

            // Extract only authorized streaming files. Never extract manifest or keys.
            if (preg_match('/^[a-zA-Z0-9_\-]+\.(m3u8|ts|jpg|png)$/', $filename)) {
                $content = $zip->getFromIndex($i);
                if ($content !== false) {
                    file_put_contents($targetdir . '/' . $filename, $content);
                }
            }
        }

        $zip->close();

        // 5. Update actual total filesize in database.
        $totalsize = storage::get_total_filesize($mediaid);
        $DB->set_field('local_securevideolibrary_media', 'filesize', $totalsize, ['id' => $mediaid]);

        return $mediaid;
    }
}
