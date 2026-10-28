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

namespace local_securevideolibrary\task;

use core\task\adhoc_task;
use local_securevideolibrary\media;
use local_securevideolibrary\storage;

/**
 * Adhoc task to transcode raw video to encrypted HLS AES-128 stream using ffmpeg.
 *
 * @package    local_securevideolibrary
 * @copyright  2025 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class transcode_video extends adhoc_task {
    /**
     * Get human-readable task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_transcode', 'local_securevideolibrary');
    }

    /**
     * Execute the transcoding pipeline.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;

        $data = $this->get_custom_data();
        if (empty($data->mediaid) || empty($data->tempfilepath)) {
            mtrace('Missing media ID or temporary filepath in adhoc task data.');
            return;
        }

        $mediaid = (int)$data->mediaid;
        $tempfilepath = $data->tempfilepath;

        $media = $DB->get_record('local_securevideolibrary_media', ['id' => $mediaid]);
        if (!$media) {
            mtrace("Media record #{$mediaid} not found in database.");
            return;
        }

        if ($media->status === media::STATUS_READY) {
            mtrace("Media record #{$mediaid} is already marked ready.");
            return;
        }

        // Set status to processing.
        $media->status = media::STATUS_PROCESSING;
        $media->timemodified = time();
        $DB->update_record('local_securevideolibrary_media', $media);

        mtrace("Starting HLS AES-128 transcode for media #{$mediaid} ({$media->title})...");

        if (!file_exists($tempfilepath) || filesize($tempfilepath) === 0) {
            $this->mark_failed($media, 'Temporary input file is missing or empty: ' . $tempfilepath);
            return;
        }

        $ffmpeg = get_config('local_securevideolibrary', 'ffmpegpath');
        if (empty($ffmpeg) || !is_executable($ffmpeg)) {
            $ffmpeg = '/usr/bin/ffmpeg';
        }

        if (!is_executable($ffmpeg)) {
            $this->mark_failed($media, "FFmpeg binary is not executable at {$ffmpeg}");
            return;
        }

        $segmentduration = (int)get_config('local_securevideolibrary', 'segmentduration');
        if ($segmentduration <= 0) {
            $segmentduration = 6;
        }

        $mediadir = storage::get_media_dir($mediaid, true);

        // 1. Generate 16-byte random key and 16-byte random IV.
        try {
            $rawkey = random_bytes(16);
            $rawiv = random_bytes(16);
        } catch (\Throwable $e) {
            $this->mark_failed($media, 'Could not generate cryptographically secure bytes: ' . $e->getMessage());
            return;
        }

        $hexkey = bin2hex($rawkey);
        $hexiv = bin2hex($rawiv);

        // 2. Prepare temporary key file and key info file for ffmpeg.
        $keytempfile = $mediadir . '/enc.key';
        file_put_contents($keytempfile, $rawkey);

        $keyinfofile = $mediadir . '/keyinfo.txt';
        // The key URL requested by player during playback.
        $keyurl = (new \moodle_url('/local/securevideolibrary/key.php', ['mediaid' => $mediaid]))->out(false);
        $keyinfocontent = "{$keyurl}\n{$keytempfile}\n{$hexiv}\n";
        file_put_contents($keyinfofile, $keyinfocontent);

        // 3. Extract duration.
        $duration = $this->probe_duration($ffmpeg, $tempfilepath);

        // 4. Run FFmpeg transcode.
        $success = false;
        $outputlog = '';

        if ($media->bitrateprofile === 'multi') {
            [$success, $outputlog] = $this->transcode_multi(
                $ffmpeg,
                $tempfilepath,
                $mediadir,
                $keyinfofile,
                $segmentduration
            );

            // Fallback to single if multi fails (e.g. video dimension smaller than ladder).
            if (!$success) {
                mtrace("Multi-bitrate transcode failed. Falling back to single-bitrate stream.");
                [$success, $outputlog] = $this->transcode_single(
                    $ffmpeg,
                    $tempfilepath,
                    $mediadir,
                    $keyinfofile,
                    $segmentduration
                );
            }
        } else {
            [$success, $outputlog] = $this->transcode_single(
                $ffmpeg,
                $tempfilepath,
                $mediadir,
                $keyinfofile,
                $segmentduration
            );
        }

        // 5. Clean up temporary encryption key files from disk (key is stored securely in DB).
        @unlink($keytempfile);
        @unlink($keyinfofile);

        if (!$success) {
            $this->mark_failed($media, 'FFmpeg transcoding error: ' . substr($outputlog, -1000));
            @unlink($tempfilepath);
            return;
        }

        // 6. Generate poster frame thumbnail.
        $this->generate_thumbnail($ffmpeg, $tempfilepath, $mediadir, $duration);

        // 7. Purge raw original file to reclaim 50%+ server disk space.
        @unlink($tempfilepath);

        // 8. Calculate total HLS directory size.
        $totalsize = storage::get_total_filesize($mediaid);

        // 9. Update DB record.
        $media->status = media::STATUS_READY;
        $media->duration = $duration;
        $media->filesize = $totalsize;
        $media->encryptionkey = $hexkey;
        $media->encryptioniv = $hexiv;
        $media->errormessage = null;
        $media->timemodified = time();
        $DB->update_record('local_securevideolibrary_media', $media);

        mtrace("Transcoding successfully completed for media #{$mediaid}. Filesize: {$totalsize} bytes.");
    }

    /**
     * Run single bitrate HLS transcode.
     *
     * @param string $ffmpeg Path to ffmpeg.
     * @param string $input Source file path.
     * @param string $mediadir Target media directory.
     * @param string $keyinfo Key info file path.
     * @param int $segmentduration Duration per segment.
     * @return array [bool success, string output]
     */
    protected function transcode_single(
        string $ffmpeg,
        string $input,
        string $mediadir,
        string $keyinfo,
        int $segmentduration
    ): array {
        $playlistpath = $mediadir . '/playlist.m3u8';
        $segmentpattern = $mediadir . '/seg_%04d.ts';

        $cmd = sprintf(
            '%s -y -i %s -c:v libx264 -preset fast -crf 22 -c:a aac -b:a 128k -ac 2 ' .
            '-hls_time %d -hls_playlist_type vod -hls_key_info_file %s -hls_segment_filename %s %s 2>&1',
            escapeshellcmd($ffmpeg),
            escapeshellarg($input),
            $segmentduration,
            escapeshellarg($keyinfo),
            escapeshellarg($segmentpattern),
            escapeshellarg($playlistpath)
        );

        exec($cmd, $output, $returncode);
        $outputstr = implode("\n", $output);
        $success = ($returncode === 0 && file_exists($playlistpath));

        return [$success, $outputstr];
    }

    /**
     * Run adaptive multi-bitrate HLS transcode (360p, 720p, 1080p).
     *
     * @param string $ffmpeg Path to ffmpeg.
     * @param string $input Source file path.
     * @param string $mediadir Target media directory.
     * @param string $keyinfo Key info file path.
     * @param int $segmentduration Duration per segment.
     * @return array [bool success, string output]
     */
    protected function transcode_multi(
        string $ffmpeg,
        string $input,
        string $mediadir,
        string $keyinfo,
        int $segmentduration
    ): array {
        $playlistpath = $mediadir . '/playlist.m3u8';
        $segmentpattern = $mediadir . '/seg_%v_%04d.ts';
        $variantpattern = $mediadir . '/variant_%v.m3u8';

        $cmd = sprintf(
            '%s -y -i %s ' .
            '-filter_complex "[v:0]split=3[v1][v2][v3];[v1]scale=w=-2:h=360[v1out];[v2]scale=w=-2:h=720[v2out];[v3]scale=w=-2:h=1080[v3out]" ' .
            '-map "[v1out]" -c:v:0 libx264 -preset fast -b:v:0 800k -maxrate:v:0 856k -bufsize:v:0 1200k ' .
            '-map "[v2out]" -c:v:1 libx264 -preset fast -b:v:1 2500k -maxrate:v:1 2675k -bufsize:v:1 3750k ' .
            '-map "[v3out]" -c:v:2 libx264 -preset fast -b:v:2 4500k -maxrate:v:2 4815k -bufsize:v:2 6750k ' .
            '-map a:0 -c:a:0 aac -b:a:0 96k -ac 2 ' .
            '-map a:0 -c:a:1 aac -b:a:1 128k -ac 2 ' .
            '-map a:0 -c:a:2 aac -b:a:2 192k -ac 2 ' .
            '-f hls -hls_time %d -hls_playlist_type vod -hls_key_info_file %s ' .
            '-hls_segment_filename %s -master_pl_name playlist.m3u8 ' .
            '-var_stream_map "v:0,a:0 v:1,a:1 v:2,a:2" %s 2>&1',
            escapeshellcmd($ffmpeg),
            escapeshellarg($input),
            $segmentduration,
            escapeshellarg($keyinfo),
            escapeshellarg($segmentpattern),
            escapeshellarg($variantpattern)
        );

        exec($cmd, $output, $returncode);
        $outputstr = implode("\n", $output);
        $success = ($returncode === 0 && file_exists($playlistpath));

        return [$success, $outputstr];
    }

    /**
     * Generate a video thumbnail image at second 2 or beginning.
     *
     * @param string $ffmpeg Path to ffmpeg.
     * @param string $input Source file path.
     * @param string $mediadir Target media directory.
     * @param int $duration Video duration in seconds.
     * @return void
     */
    protected function generate_thumbnail(string $ffmpeg, string $input, string $mediadir, int $duration): void {
        $thumbnailpath = $mediadir . '/thumbnail.jpg';
        $seektime = ($duration >= 3) ? '00:00:02' : '00:00:00';

        $cmd = sprintf(
            '%s -y -ss %s -i %s -vframes 1 -q:v 2 %s 2>&1',
            escapeshellcmd($ffmpeg),
            $seektime,
            escapeshellarg($input),
            escapeshellarg($thumbnailpath)
        );

        exec($cmd);
    }

    /**
     * Probe video duration using ffmpeg output.
     *
     * @param string $ffmpeg Path to ffmpeg.
     * @param string $input Source file path.
     * @return int Duration in whole seconds.
     */
    protected function probe_duration(string $ffmpeg, string $input): int {
        $cmd = sprintf('%s -i %s 2>&1', escapeshellcmd($ffmpeg), escapeshellarg($input));
        exec($cmd, $output);
        $outputstr = implode("\n", $output);

        if (preg_match('/Duration:\s*(\d{2}):(\d{2}):(\d{2})\.(\d+)/', $outputstr, $matches)) {
            $hours = (int)$matches[1];
            $minutes = (int)$matches[2];
            $seconds = (int)$matches[3];
            return ($hours * 3600) + ($minutes * 60) + $seconds;
        }

        return 0;
    }

    /**
     * Mark media record as failed with an error message.
     *
     * @param \stdClass $media Database record.
     * @param string $error Error message.
     * @return void
     */
    protected function mark_failed(\stdClass $media, string $error): void {
        global $DB;
        mtrace("Transcode failed for media #{$media->id}: {$error}");
        $media->status = media::STATUS_FAILED;
        $media->errormessage = $error;
        $media->timemodified = time();
        $DB->update_record('local_securevideolibrary_media', $media);
    }
}
