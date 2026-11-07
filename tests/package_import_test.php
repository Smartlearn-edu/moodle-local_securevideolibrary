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
 * Standalone verification test for the Package Importer.
 *
 * @package    local_securevideolibrary
 * @copyright  2025 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

echo "========================================================\n";
echo "🧪 Running SecureVideo Package Importer Verification Test\n";
echo "========================================================\n\n";

$testpkg = __DIR__ . '/../../desktop-encoder/test_out/Biology_Lecture_101.svideo.zip';
if (!file_exists($testpkg)) {
    echo "❌ Error: Test package not found at: {$testpkg}\n";
    exit(1);
}

echo "1️⃣ Verifying test package file exists...\n";
echo "   ✅ Found package: " . basename($testpkg) . " (" . filesize($testpkg) . " bytes)\n\n";

// 2. Open ZIP and inspect manifest
echo "2️⃣ Inspecting manifest.json inside ZIP...\n";
$zip = new ZipArchive();
if ($zip->open($testpkg) !== true) {
    echo "❌ Could not open ZIP archive.\n";
    exit(1);
}

$manifestjson = $zip->getFromName('manifest.json');
if (!$manifestjson) {
    echo "❌ manifest.json not found in zip archive.\n";
    exit(1);
}

$manifest = json_decode($manifestjson);
if (!$manifest || $manifest->format !== 'local_securevideolibrary_package') {
    echo "❌ manifest format invalid.\n";
    exit(1);
}

echo "   ✅ Title       : " . $manifest->title . "\n";
echo "   ✅ Duration    : " . $manifest->duration . "s\n";
echo "   ✅ Ladder      : " . $manifest->bitrateprofile . "\n";
echo "   ✅ Key Length  : " . strlen($manifest->encryptionkey) . " chars (Hex)\n";
echo "   ✅ IV Length   : " . strlen($manifest->encryptioniv) . " chars (Hex)\n";
echo "   ✅ Segments    : " . $manifest->segmentcount . "\n\n";

// 3. Security checks: ensure raw key files are NOT in zip
echo "3️⃣ Performing security validation...\n";
for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = basename($zip->getNameIndex($i));
    if ($name === 'enc.key' || $name === 'keyinfo.txt') {
        echo "❌ SECURITY FAILURE: Found {$name} in archive!\n";
        exit(1);
    }
}
echo "   ✅ Security passed: Zero raw key files found in ZIP archive.\n\n";

// 4. Test extraction of streaming assets to simulated dataroot
echo "4️⃣ Extracting streaming assets to simulated dataroot...\n";
$simulateddataroot = sys_get_temp_dir() . '/securevideo_import_test_' . uniqid();
mkdir($simulateddataroot, 0777, true);

$extractedcount = 0;
for ($i = 0; $i < $zip->numFiles; $i++) {
    $filename = basename($zip->getNameIndex($i));
    if (preg_match('/^[a-zA-Z0-9_\-]+\.(m3u8|ts|jpg|png)$/', $filename)) {
        $content = $zip->getFromIndex($i);
        file_put_contents($simulateddataroot . '/' . $filename, $content);
        $extractedcount++;
    }
}
$zip->close();

echo "   ✅ Extracted {$extractedcount} streaming assets.\n";
assert(file_exists($simulateddataroot . '/playlist.m3u8'));
assert(file_exists($simulateddataroot . '/thumbnail.jpg'));
echo "   ✅ playlist.m3u8 and thumbnail.jpg verified.\n\n";

// 5. Test FFmpeg decryption with extracted manifest key
echo "5️⃣ Testing AES-128 decryption using manifest key...\n";
$rawkey = hex2bin($manifest->encryptionkey);
file_put_contents($simulateddataroot . '/verify.key', $rawkey);

// Rewrite playlist key to verify.key
$playlist = file_get_contents($simulateddataroot . '/playlist.m3u8');
$re = '/#EXT-X-KEY:METHOD=AES-128,URI="[^"]+",IV=(0x[a-fA-F0-9]+)/';

// For multi-bitrate, check variant playlists
$variants = glob($simulateddataroot . '/variant_*.m3u8');
foreach ($variants as $vfile) {
    $content = file_get_contents($vfile);
    $rewritten = preg_replace($re, '#EXT-X-KEY:METHOD=AES-128,URI="verify.key",IV=$1', $content);
    file_put_contents($vfile, $rewritten);
}

$decodeCmd = sprintf(
    'ffmpeg -v error -allowed_extensions ALL -i %s -f null - 2>&1',
    escapeshellarg($simulateddataroot . '/playlist.m3u8')
);
exec($decodeCmd, $decodeOut, $decodeCode);

if ($decodeCode !== 0 || !empty($decodeOut)) {
    echo "❌ FFmpeg decryption test failed:\n" . implode("\n", $decodeOut) . "\n";
    exit(1);
}

echo "   ✅ Full stream AES-128 decryption succeeded with 0 errors!\n\n";

// Cleanup
unlink($simulateddataroot . '/verify.key');
array_map('unlink', glob("{$simulateddataroot}/*.*"));
rmdir($simulateddataroot);

echo "========================================================\n";
echo "🎉 ALL PACKAGE IMPORTER TESTS PASSED SUCCESSFULLY!\n";
echo "========================================================\n";
