# Moodle Secure Video Library (`local_securevideolibrary`)

Centralized, role-based video management, background HLS AES-128 transcoding queue via `ffmpeg`, direct filesystem storage in `$CFG->dataroot/securevideo/{media_id}/`, and session-locked decryption key distribution.

Strictly designed for **Moodle 5.x+ (PHP 8.2+, Boost / Bootstrap 5, public/ webroot layout)**.

---

## 🔒 Security & Transcoding Architecture

```
[Teacher / Admin]
       │
       ▼ (Uploads MP4/MOV)
[local_securevideolibrary]
       │
       ▼ (Queues Adhoc Task: \local_securevideolibrary\task\transcode_video)
[Server FFmpeg Worker]
       ├── Generates random 16-byte cryptographic AES-128 key & IV
       ├── Encrypts video segments (-hls_key_info_file pointing to key.php)
       ├── Outputs playlist.m3u8 + seg_0000.ts, seg_0001.ts...
       ├── Extracts video poster frame (thumbnail.jpg)
       ├── Purges raw source video file to save 50%+ server disk space
       ├── Stores encrypted segments in $CFG->dataroot/securevideo/{media_id}/
       └── Updates status in DB: STATUS_READY
```

### Why ordinary students & downloaders cannot download:
1. **Encrypted Ciphertext:** Intercepted `.ts` segments are raw AES-128 blocks that cannot be played in VLC or any media player.
2. **Session-Protected Key:** HLS decryption keys are delivered strictly via `key.php?mediaid=X` after validating `require_login()` and capability checks. External scrapers and IDM receive HTTP `403 Forbidden`.
3. **No Database Bloat:** Stored directly in `dataroot/securevideo/{id}/` without cluttering Moodle's `mdl_files` table with tens of thousands of segment records.

---

## ⚙️ Requirements
* **Moodle:** 4.5+ / 5.0+ / 5.3+
* **PHP:** 8.2+ (with OpenSSL extension)
* **FFmpeg:** Installed on server (default path `/usr/bin/ffmpeg`)

---

## 🚀 Installation & Setup
1. Copy or symlink this folder to your Moodle directory at `public/local/securevideolibrary`.
2. Visit **Site administration > Notifications** or run:
   ```bash
   php admin/cli/upgrade.php
   ```
3. Configure the FFmpeg binary path under **Site administration > Plugins > Local plugins > Secure Video Library**.
4. Access the library at **Site administration > Plugins > Local plugins > Manage video library** (`/local/securevideolibrary/manage.php`) or through the course secondary navigation tab.

---

## 🧪 Testing Transcoding via Adhoc Task
When a video is uploaded, Moodle queues an adhoc task. To immediately process queued videos via CLI:
```bash
php admin/cli/adhoc_task.php --execute=\\local_securevideolibrary\\task\\transcode_video
```

---

## 📜 License
Licensed under the GNU General Public License v3 or later.
Copyright &copy; 2025 Mohammad Nabil <mohammad@smartlearn.education>.
