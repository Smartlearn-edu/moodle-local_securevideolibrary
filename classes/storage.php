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
 * Storage and filesystem management for encrypted video HLS segments.
 *
 * @package    local_securevideolibrary
 * @copyright  2025 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class storage {
    /**
     * Get the root directory for permanent encrypted video assets in dataroot.
     *
     * @return string Absolute filesystem path.
     */
    public static function get_base_dir(): string {
        global $CFG;
        $dir = $CFG->dataroot . '/securevideo';
        check_dir_exists($dir, true, true);
        return $dir;
    }

    /**
     * Get the directory for temporary incoming video uploads awaiting transcoding.
     *
     * @return string Absolute filesystem path.
     */
    public static function get_temp_dir(): string {
        global $CFG;
        $dir = $CFG->dataroot . '/securevideo_temp';
        check_dir_exists($dir, true, true);
        return $dir;
    }

    /**
     * Get the directory for a specific media item.
     *
     * @param int $mediaid Media ID.
     * @param bool $create Whether to create the directory if it does not exist.
     * @return string Absolute filesystem path.
     */
    public static function get_media_dir(int $mediaid, bool $create = true): string {
        $dir = self::get_base_dir() . '/' . $mediaid;
        if ($create) {
            check_dir_exists($dir, true, true);
        }
        return $dir;
    }

    /**
     * Get the path to the master/single HLS playlist file.
     *
     * @param int $mediaid Media ID.
     * @return string|null Absolute path or null if it doesn't exist.
     */
    public static function get_playlist_path(int $mediaid): ?string {
        $path = self::get_media_dir($mediaid, false) . '/playlist.m3u8';
        return file_exists($path) ? $path : null;
    }

    /**
     * Get the path to a specific file or segment within a media directory.
     * Strictly verifies against directory traversal attacks.
     *
     * @param int $mediaid Media ID.
     * @param string $filename Filename requested (e.g. seg_0001.ts, playlist.m3u8, thumbnail.jpg).
     * @return string|null Path if valid and exists, null otherwise.
     */
    public static function get_file_path(int $mediaid, string $filename): ?string {
        // Enforce strict whitelist of allowed filenames and extensions.
        if (!preg_match('/^[a-zA-Z0-9_\-]+\.(m3u8|ts|jpg|png)$/', $filename)) {
            return null;
        }

        $mediadir = self::get_media_dir($mediaid, false);
        $filepath = $mediadir . '/' . $filename;

        // Ensure canonical path stays strictly inside mediadir.
        $realmediadir = realpath($mediadir);
        $realfilepath = realpath($filepath);

        if ($realmediadir === false || $realfilepath === false) {
            return null;
        }

        if (strpos($realfilepath, $realmediadir . DIRECTORY_SEPARATOR) !== 0 && $realfilepath !== $realmediadir) {
            return null;
        }

        return file_exists($realfilepath) ? $realfilepath : null;
    }

    /**
     * Get the path to the video thumbnail image.
     *
     * @param int $mediaid Media ID.
     * @return string|null Absolute path or null if not found.
     */
    public static function get_thumbnail_path(int $mediaid): ?string {
        $path = self::get_media_dir($mediaid, false) . '/thumbnail.jpg';
        return file_exists($path) ? $path : null;
    }

    /**
     * Calculate the total size in bytes of all HLS files for a media item.
     *
     * @param int $mediaid Media ID.
     * @return int Size in bytes.
     */
    public static function get_total_filesize(int $mediaid): int {
        $dir = self::get_media_dir($mediaid, false);
        if (!is_dir($dir)) {
            return 0;
        }

        $totalsize = 0;
        $files = scandir($dir);
        if ($files === false) {
            return 0;
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $filepath = $dir . '/' . $file;
            if (is_file($filepath)) {
                $totalsize += (int)filesize($filepath);
            }
        }

        return $totalsize;
    }

    /**
     * Delete all files and the directory for a given media item.
     *
     * @param int $mediaid Media ID.
     * @return bool True on success.
     */
    public static function delete_media_files(int $mediaid): bool {
        $dir = self::get_media_dir($mediaid, false);
        if (!is_dir($dir)) {
            return true;
        }

        $files = scandir($dir);
        if ($files !== false) {
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                $filepath = $dir . '/' . $file;
                if (is_file($filepath)) {
                    @unlink($filepath);
                }
            }
        }

        return @rmdir($dir);
    }
}
