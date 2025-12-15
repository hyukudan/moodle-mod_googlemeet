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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace mod_googlemeet\task;

use mod_googlemeet\gemini_client;
use core\task\adhoc_task;
use stdClass;

/**
 * Adhoc task to process video analysis in the background.
 *
 * This task downloads a video from Google Drive, uploads it to Gemini File API,
 * waits for processing, generates the analysis, and saves the results.
 *
 * @package     mod_googlemeet
 * @copyright   2024 Your Name
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_video_analysis extends adhoc_task {

    /**
     * Get the task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('ai_process_video_task', 'googlemeet');
    }

    /**
     * Execute the task.
     */
    public function execute() {
        global $DB;

        $data = $this->get_custom_data();
        $recordingid = $data->recordingid;
        $analysisid = $data->analysisid;

        mtrace("Processing video analysis for recording {$recordingid}...");

        // Get the recording and analysis records.
        $recording = $DB->get_record('googlemeet_recordings', ['id' => $recordingid]);
        if (!$recording) {
            mtrace("Recording {$recordingid} not found, aborting.");
            return;
        }

        $analysis = $DB->get_record('googlemeet_ai_analysis', ['id' => $analysisid]);
        if (!$analysis) {
            mtrace("Analysis {$analysisid} not found, aborting.");
            return;
        }

        // Update status to processing.
        $analysis->status = 'processing';
        $analysis->timemodified = time();
        $DB->update_record('googlemeet_ai_analysis', $analysis);

        try {
            $client = new gemini_client();

            if (!$client->is_configured()) {
                throw new \moodle_exception('ai_not_configured', 'googlemeet');
            }

            // Check if we have a transcript available (much faster path).
            if (!empty($recording->transcripttext)) {
                mtrace("Transcript available, using fast text-based analysis...");
                $result = $this->analyze_with_transcript($client, $recording);
            } else {
                mtrace("No transcript available, using video-based analysis...");
                $result = $this->analyze_with_video($client, $recording);
            }

            // Update the analysis record with results.
            $analysis->summary = $result->summary;
            $analysis->keypoints = json_encode($result->keypoints);
            $analysis->topics = json_encode($result->topics);
            $analysis->transcript = $result->transcript;
            $analysis->language = $result->language;
            $analysis->status = 'completed';
            $analysis->error = null;
            $analysis->aimodel = $client->get_model();
            $analysis->timemodified = time();
            $DB->update_record('googlemeet_ai_analysis', $analysis);

            mtrace("Analysis saved successfully.");

        } catch (\Exception $e) {
            mtrace("Error: " . $e->getMessage());

            // Update analysis with error status.
            $analysis->status = 'failed';
            $analysis->error = $e->getMessage();
            $analysis->timemodified = time();
            $DB->update_record('googlemeet_ai_analysis', $analysis);
        }
    }

    /**
     * Analyze using the transcript text (fast path).
     *
     * @param gemini_client $client The Gemini client
     * @param stdClass $recording The recording record
     * @return stdClass The analysis result
     */
    private function analyze_with_transcript(gemini_client $client, stdClass $recording): stdClass {
        return $client->analyze_transcript(
            $recording->transcripttext,
            $recording->name,
            $recording->duration
        );
    }

    /**
     * Analyze by downloading and uploading the video (slow path).
     *
     * @param gemini_client $client The Gemini client
     * @param stdClass $recording The recording record
     * @return stdClass The analysis result
     */
    private function analyze_with_video(gemini_client $client, stdClass $recording): stdClass {
        // Extract file ID from Google Drive URL.
        $fileid = $this->extract_drive_file_id($recording->webviewlink);
        if (!$fileid) {
            throw new \moodle_exception('ai_error', 'googlemeet', '', 'Could not extract file ID from Drive URL');
        }

        mtrace("Extracted Drive file ID: {$fileid}");

        $tempfile = null;
        $filedata = null;

        try {
            // Download the video from Google Drive.
            $tempfile = $this->download_from_drive($fileid, $recording->name);
            mtrace("Downloaded video to: {$tempfile}");

            // Determine MIME type.
            $mimetype = $this->get_video_mimetype($tempfile, $recording->name);
            mtrace("Video MIME type: {$mimetype}");

            // Upload to Gemini File API.
            mtrace("Uploading to Gemini File API...");
            $filedata = $client->upload_video_file($tempfile, $mimetype, $recording->name);
            mtrace("Uploaded successfully. File name: {$filedata->name}");

            // Wait for file to be processed.
            mtrace("Waiting for file processing...");
            $processedfile = $client->wait_for_file_processing($filedata->name, 900); // 15 minutes max.
            mtrace("File is ready for analysis.");

            // Generate the analysis.
            mtrace("Generating analysis...");
            $result = $client->analyze_video_with_file(
                $processedfile->uri,
                $mimetype,
                $recording->name,
                $recording->duration
            );
            mtrace("Analysis completed.");

            // Clean up: delete the file from Gemini.
            $client->delete_file($filedata->name);
            mtrace("Cleaned up Gemini file.");

            return $result;

        } finally {
            // Clean up: delete the temp file.
            if ($tempfile && file_exists($tempfile)) {
                unlink($tempfile);
                mtrace("Cleaned up temp file.");
            }
        }
    }

    /**
     * Extract the file ID from a Google Drive URL.
     *
     * @param string $url The Google Drive URL
     * @return string|null The file ID or null if not found
     */
    private function extract_drive_file_id(string $url): ?string {
        // Match various Google Drive URL formats.
        $patterns = [
            '/\/file\/d\/([a-zA-Z0-9_-]+)/',           // /file/d/FILE_ID/
            '/id=([a-zA-Z0-9_-]+)/',                   // ?id=FILE_ID
            '/\/d\/([a-zA-Z0-9_-]+)/',                 // /d/FILE_ID/
            '/open\?id=([a-zA-Z0-9_-]+)/',             // open?id=FILE_ID
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * Download a file from Google Drive.
     *
     * @param string $fileid The Google Drive file ID
     * @param string $filename The original filename
     * @return string Path to the downloaded temp file
     * @throws \moodle_exception If download fails
     */
    private function download_from_drive(string $fileid, string $filename): string {
        global $CFG;

        // Create temp directory if it doesn't exist.
        $tempdir = $CFG->tempdir . '/googlemeet_ai';
        if (!is_dir($tempdir)) {
            mkdir($tempdir, 0777, true);
        }

        // Clean up old temp files first (older than 1 hour).
        $this->cleanup_old_temp_files($tempdir, 3600);

        // Check available disk space (require at least 3GB free).
        $freespace = disk_free_space($tempdir);
        if ($freespace !== false && $freespace < 3221225472) { // 3GB in bytes.
            throw new \moodle_exception('ai_error', 'googlemeet', '',
                'Insufficient disk space for video download. Need at least 3GB free.');
        }

        // Generate temp filename.
        $ext = pathinfo($filename, PATHINFO_EXTENSION) ?: 'mp4';
        $tempfile = $tempdir . '/' . uniqid('video_') . '.' . $ext;

        // Try to download using the direct download URL.
        // This works for files that are shared publicly or with "anyone with the link".
        $downloadurl = "https://drive.google.com/uc?export=download&id={$fileid}";

        mtrace("Attempting download from: {$downloadurl}");

        $curl = new \curl();
        $options = [
            'CURLOPT_TIMEOUT' => 1800,        // 30 minutes for large files.
            'CURLOPT_CONNECTTIMEOUT' => 60,
            'CURLOPT_FOLLOWLOCATION' => true,
            'CURLOPT_MAXREDIRS' => 5,
        ];

        // First request to get the download.
        $response = $curl->download_one($downloadurl, null, $options);

        // Check if we got a virus scan warning page (for large files).
        if ($response === true && file_exists($tempfile) === false) {
            // We need to handle the confirmation page.
            $response = $curl->get($downloadurl);

            // Look for the confirm token.
            if (preg_match('/confirm=([^&]+)/', $response, $matches)) {
                $confirmtoken = $matches[1];
                $downloadurl = "https://drive.google.com/uc?export=download&confirm={$confirmtoken}&id={$fileid}";
                mtrace("Large file detected, using confirm token...");
            }
        }

        // Download to file.
        $fp = fopen($tempfile, 'w');
        if (!$fp) {
            throw new \moodle_exception('ai_error', 'googlemeet', '', 'Cannot create temp file');
        }

        $curl2 = new \curl();
        $curl2->setopt([
            'CURLOPT_TIMEOUT' => 1800,
            'CURLOPT_CONNECTTIMEOUT' => 60,
            'CURLOPT_FOLLOWLOCATION' => true,
            'CURLOPT_MAXREDIRS' => 10,
            'CURLOPT_FILE' => $fp,
        ]);

        $curl2->get($downloadurl);
        fclose($fp);

        $info = $curl2->get_info();

        // Check if download was successful.
        if (!file_exists($tempfile) || filesize($tempfile) < 1000) {
            if (file_exists($tempfile)) {
                // Check if it's an error page.
                $content = file_get_contents($tempfile);
                if (strpos($content, '<html') !== false) {
                    unlink($tempfile);
                    throw new \moodle_exception('ai_error', 'googlemeet', '',
                        'Cannot download video. Make sure the file is shared with "Anyone with the link" permission.');
                }
            }
            throw new \moodle_exception('ai_error', 'googlemeet', '', 'Download failed or file too small');
        }

        $filesize = filesize($tempfile);
        mtrace("Downloaded {$filesize} bytes");

        // Check file size limit (2GB for Gemini).
        if ($filesize > 2147483648) {
            unlink($tempfile);
            throw new \moodle_exception('ai_error', 'googlemeet', '', 'Video file exceeds 2GB limit');
        }

        return $tempfile;
    }

    /**
     * Determine the MIME type of a video file.
     *
     * @param string $filepath Path to the file
     * @param string $filename Original filename
     * @return string The MIME type
     */
    private function get_video_mimetype(string $filepath, string $filename): string {
        // Try to detect from file content.
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimetype = finfo_file($finfo, $filepath);
        finfo_close($finfo);

        if ($mimetype && strpos($mimetype, 'video/') === 0) {
            return $mimetype;
        }

        // Fall back to extension-based detection.
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mimetypes = [
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            'avi' => 'video/x-msvideo',
            'mkv' => 'video/x-matroska',
            'wmv' => 'video/x-ms-wmv',
            'flv' => 'video/x-flv',
            'm4v' => 'video/x-m4v',
        ];

        return $mimetypes[$ext] ?? 'video/mp4';
    }

    /**
     * Clean up old temporary files from the temp directory.
     *
     * @param string $tempdir The temporary directory path
     * @param int $maxage Maximum age in seconds (files older than this will be deleted)
     */
    private function cleanup_old_temp_files(string $tempdir, int $maxage): void {
        if (!is_dir($tempdir)) {
            return;
        }

        $now = time();
        $files = scandir($tempdir);

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $filepath = $tempdir . '/' . $file;

            // Only delete files, not directories.
            if (!is_file($filepath)) {
                continue;
            }

            // Check if file is older than maxage.
            $filemtime = filemtime($filepath);
            if ($filemtime !== false && ($now - $filemtime) > $maxage) {
                if (unlink($filepath)) {
                    mtrace("Cleaned up old temp file: {$file}");
                }
            }
        }
    }
}
