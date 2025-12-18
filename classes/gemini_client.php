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

namespace mod_googlemeet;

use stdClass;
use moodle_exception;

// Note: curl class is from Moodle core (lib/filelib.php) in the global namespace.

/**
 * Gemini API Client for mod_googlemeet.
 *
 * Handles communication with Google's Gemini AI API for generating
 * video summaries, key points, and transcripts.
 *
 * @package     mod_googlemeet
 * @copyright   2024 Your Name
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class gemini_client {

    /** @var string The Gemini API base URL */
    private const API_BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/models/';

    /** @var string The File API base URL */
    private const FILE_API_URL = 'https://generativelanguage.googleapis.com/v1beta/files';

    /** @var string The File Upload URL */
    private const UPLOAD_URL = 'https://generativelanguage.googleapis.com/upload/v1beta/files';

    /** @var string The API key */
    private $apikey;

    /** @var string The model to use */
    private $model;

    /** @var bool Whether AI features are enabled */
    private $enabled;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->enabled = (bool) get_config('googlemeet', 'enableai');
        $this->apikey = get_config('googlemeet', 'geminiapikey');
        $this->model = get_config('googlemeet', 'aimodel') ?: 'gemini-3-flash-preview';
    }

    /**
     * Check if AI features are properly configured and enabled.
     *
     * @return bool
     */
    public function is_configured(): bool {
        return $this->enabled && !empty($this->apikey);
    }

    /**
     * Get the current model name.
     *
     * @return string
     */
    public function get_model(): string {
        return $this->model;
    }

    /**
     * Generate analysis for a video recording.
     *
     * @param string $videourl The Google Drive video URL
     * @param string $videoname The name of the video
     * @param string $duration The duration of the video
     * @return stdClass Object containing summary, keypoints, topics, and transcript
     * @throws moodle_exception If the API call fails
     */
    public function analyze_video(string $videourl, string $videoname, string $duration): stdClass {
        if (!$this->is_configured()) {
            throw new moodle_exception('ai_not_configured', 'googlemeet');
        }

        $prompt = $this->build_analysis_prompt($videoname, $duration, $videourl);

        $response = $this->call_api($prompt);

        return $this->parse_analysis_response($response);
    }

    /**
     * Generate only a summary for a video.
     *
     * @param string $videourl The Google Drive video URL
     * @param string $videoname The name of the video
     * @return string The summary
     * @throws moodle_exception If the API call fails
     */
    public function generate_summary(string $videourl, string $videoname): string {
        if (!$this->is_configured()) {
            throw new moodle_exception('ai_not_configured', 'googlemeet');
        }

        $prompt = "Please watch this video and provide a concise summary (2-3 paragraphs) of the main content. "
                . "The video is titled: '{$videoname}'. "
                . "Video URL: {$videourl}\n\n"
                . "Focus on the key topics discussed, main conclusions, and any important information shared.";

        $response = $this->call_api($prompt);

        return $this->extract_text_from_response($response);
    }

    /**
     * Build the full analysis prompt.
     *
     * @param string $videoname The video name
     * @param string $duration The video duration
     * @param string $videourl The video URL
     * @return string The prompt
     */
    private function build_analysis_prompt(string $videoname, string $duration, string $videourl): string {
        return <<<PROMPT
You are an educational assistant analyzing a recorded meeting/class video.

Video Information:
- Title: {$videoname}
- Duration: {$duration}
- URL: {$videourl}

Please analyze this video and provide the following in a structured JSON format:

1. **Summary**: A comprehensive summary of the video content (2-3 paragraphs)
2. **Key Points**: A list of 5-10 main takeaways or important points discussed
3. **Topics**: A list of main topics/themes covered in the video
4. **Transcript Summary**: If audio is available, provide a condensed transcript of the main discussions

IMPORTANT: Respond ONLY with valid JSON in the following format (no markdown, no code blocks):
{
    "summary": "Your comprehensive summary here...",
    "keypoints": ["Point 1", "Point 2", "Point 3", ...],
    "topics": ["Topic 1", "Topic 2", "Topic 3", ...],
    "transcript": "Condensed transcript or 'Not available' if cannot be generated...",
    "language": "detected language code (e.g., en, es, fr)"
}

If you cannot access the video, analyze based on the title and provide your best interpretation, noting that direct video analysis was not possible.
PROMPT;
    }

    /**
     * Call the Gemini API.
     *
     * @param string $prompt The prompt to send
     * @return string The API response
     * @throws moodle_exception If the API call fails
     */
    private function call_api(string $prompt): string {
        $url = self::API_BASE_URL . $this->model . ':generateContent?key=' . $this->apikey;

        // Log that we're making the API call (without exposing the API key).
        $safeurl = self::API_BASE_URL . $this->model . ':generateContent?key=***';
        debugging("Gemini API: Starting request to {$safeurl}", DEBUG_DEVELOPER);

        $data = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.7,
                'topK' => 40,
                'topP' => 0.95,
                'maxOutputTokens' => 8192,
            ],
            'safetySettings' => [
                [
                    'category' => 'HARM_CATEGORY_HARASSMENT',
                    'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
                ],
                [
                    'category' => 'HARM_CATEGORY_HATE_SPEECH',
                    'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
                ],
                [
                    'category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT',
                    'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
                ],
                [
                    'category' => 'HARM_CATEGORY_DANGEROUS_CONTENT',
                    'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
                ]
            ]
        ];

        $curl = new \curl();
        $curl->setHeader(['Content-Type: application/json']);

        // Set timeout options for potentially long AI processing.
        $options = [
            'CURLOPT_TIMEOUT' => 120,        // 2 minutes max total time.
            'CURLOPT_CONNECTTIMEOUT' => 30,  // 30 seconds to connect.
        ];

        $response = $curl->post($url, json_encode($data), $options);

        $info = $curl->get_info();
        $httpcode = $info['http_code'] ?? 0;
        $curlerror = $curl->get_errno();

        // Check for curl errors first.
        if ($curlerror) {
            $errormsg = $curl->error;
            debugging("Gemini API curl error: {$errormsg}", DEBUG_DEVELOPER);
            throw new moodle_exception('ai_error', 'googlemeet', '', "Connection error: {$errormsg}");
        }

        // Check for HTTP errors.
        if ($httpcode === 0) {
            debugging("Gemini API: No response received", DEBUG_DEVELOPER);
            throw new moodle_exception('ai_error', 'googlemeet', '', 'No response from API - check network connectivity');
        }

        if ($httpcode !== 200) {
            $error = json_decode($response);
            $errormsg = isset($error->error->message) ? $error->error->message : "HTTP error {$httpcode}";
            debugging("Gemini API error: {$errormsg}", DEBUG_DEVELOPER);
            throw new moodle_exception('ai_error', 'googlemeet', '', $errormsg);
        }

        return $response;
    }

    /**
     * Parse the analysis response from the API.
     *
     * @param string $response The raw API response
     * @return stdClass Parsed analysis object
     * @throws moodle_exception If parsing fails
     */
    private function parse_analysis_response(string $response): stdClass {
        $decoded = json_decode($response);

        if (!$decoded || !isset($decoded->candidates[0]->content->parts[0]->text)) {
            throw new moodle_exception('ai_error', 'googlemeet', '', 'Invalid API response format');
        }

        $text = $decoded->candidates[0]->content->parts[0]->text;

        // Try to extract JSON from the response.
        $text = trim($text);

        // Remove markdown code blocks if present.
        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/', $text, $matches)) {
            $text = $matches[1];
        }

        $analysis = json_decode($text);

        if (!$analysis) {
            // If JSON parsing fails, create a basic structure from the text.
            $analysis = new stdClass();
            $analysis->summary = $text;
            $analysis->keypoints = [];
            $analysis->topics = [];
            $analysis->transcript = '';
            $analysis->language = 'en';
        }

        // Ensure all fields exist.
        $result = new stdClass();
        $result->summary = $analysis->summary ?? '';
        $result->keypoints = $analysis->keypoints ?? [];
        $result->topics = $analysis->topics ?? [];
        $result->transcript = $analysis->transcript ?? '';
        $result->language = $analysis->language ?? 'en';

        return $result;
    }

    /**
     * Extract plain text from API response.
     *
     * @param string $response The raw API response
     * @return string The extracted text
     * @throws moodle_exception If extraction fails
     */
    private function extract_text_from_response(string $response): string {
        $decoded = json_decode($response);

        if (!$decoded || !isset($decoded->candidates[0]->content->parts[0]->text)) {
            throw new moodle_exception('ai_error', 'googlemeet', '', 'Invalid API response format');
        }

        return $decoded->candidates[0]->content->parts[0]->text;
    }

    /**
     * Test the API connection with a simple request.
     *
     * @return bool True if connection is successful
     */
    public function test_connection(): bool {
        if (!$this->is_configured()) {
            return false;
        }

        try {
            $prompt = "Reply with exactly: OK";
            $response = $this->call_api($prompt);
            return !empty($response);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Upload a video file to Gemini File API.
     *
     * @param string $filepath Path to the video file
     * @param string $mimetype The MIME type of the video
     * @param string $displayname Display name for the file
     * @return stdClass The file metadata including URI
     * @throws moodle_exception If upload fails
     */
    public function upload_video_file(string $filepath, string $mimetype, string $displayname): stdClass {
        if (!$this->is_configured()) {
            throw new moodle_exception('ai_not_configured', 'googlemeet');
        }

        if (!file_exists($filepath)) {
            throw new moodle_exception('ai_error', 'googlemeet', '', 'Video file not found');
        }

        $filesize = filesize($filepath);
        debugging("Gemini File API: Uploading file {$displayname} ({$filesize} bytes)", DEBUG_DEVELOPER);

        // Start resumable upload.
        $url = self::UPLOAD_URL . '?key=' . $this->apikey;

        $curl = new \curl();
        $curl->setHeader([
            'X-Goog-Upload-Protocol: resumable',
            'X-Goog-Upload-Command: start',
            'X-Goog-Upload-Header-Content-Length: ' . $filesize,
            'X-Goog-Upload-Header-Content-Type: ' . $mimetype,
            'Content-Type: application/json',
        ]);

        $metadata = json_encode(['file' => ['display_name' => $displayname]]);
        $response = $curl->post($url, $metadata);
        $info = $curl->get_info();

        if ($info['http_code'] !== 200) {
            debugging("Gemini File API: Failed to start upload, HTTP " . $info['http_code'], DEBUG_DEVELOPER);
            throw new moodle_exception('ai_error', 'googlemeet', '', 'Failed to start file upload');
        }

        // Get the upload URL from response headers.
        $uploadurl = $curl->getResponse()['X-Goog-Upload-URL'] ?? null;
        if (!$uploadurl) {
            // Try to get from response headers differently.
            $headers = $curl->get_raw_response_headers();
            if (preg_match('/x-goog-upload-url:\s*(.+)/i', $headers, $matches)) {
                $uploadurl = trim($matches[1]);
            }
        }

        if (!$uploadurl) {
            debugging("Gemini File API: No upload URL received", DEBUG_DEVELOPER);
            throw new moodle_exception('ai_error', 'googlemeet', '', 'No upload URL received from API');
        }

        debugging("Gemini File API: Got upload URL, uploading file content...", DEBUG_DEVELOPER);

        // Upload the actual file content.
        $curl2 = new \curl();
        $curl2->setHeader([
            'Content-Length: ' . $filesize,
            'X-Goog-Upload-Offset: 0',
            'X-Goog-Upload-Command: upload, finalize',
        ]);

        $options = [
            'CURLOPT_TIMEOUT' => 600,        // 10 minutes for large files.
            'CURLOPT_CONNECTTIMEOUT' => 60,
        ];

        $filecontent = file_get_contents($filepath);
        $response = $curl2->post($uploadurl, $filecontent, $options);
        $info = $curl2->get_info();

        if ($info['http_code'] !== 200) {
            debugging("Gemini File API: Upload failed, HTTP " . $info['http_code'], DEBUG_DEVELOPER);
            throw new moodle_exception('ai_error', 'googlemeet', '', 'Failed to upload file content');
        }

        $filedata = json_decode($response);
        if (!$filedata || !isset($filedata->file)) {
            debugging("Gemini File API: Invalid response after upload", DEBUG_DEVELOPER);
            throw new moodle_exception('ai_error', 'googlemeet', '', 'Invalid response after file upload');
        }

        debugging("Gemini File API: File uploaded successfully, URI: " . $filedata->file->uri, DEBUG_DEVELOPER);

        return $filedata->file;
    }

    /**
     * Wait for a file to be processed and ready.
     *
     * @param string $filename The file name (e.g., "files/abc123")
     * @param int $maxwait Maximum seconds to wait
     * @return stdClass The file metadata
     * @throws moodle_exception If file processing fails or times out
     */
    public function wait_for_file_processing(string $filename, int $maxwait = 600): stdClass {
        $url = self::FILE_API_URL . '/' . $filename . '?key=' . $this->apikey;
        $starttime = time();

        debugging("Gemini File API: Waiting for file {$filename} to be processed...", DEBUG_DEVELOPER);

        while (time() - $starttime < $maxwait) {
            $curl = new \curl();
            $response = $curl->get($url);
            $info = $curl->get_info();

            if ($info['http_code'] !== 200) {
                throw new moodle_exception('ai_error', 'googlemeet', '', 'Failed to check file status');
            }

            $filedata = json_decode($response);
            if (!$filedata) {
                throw new moodle_exception('ai_error', 'googlemeet', '', 'Invalid file status response');
            }

            $state = $filedata->state ?? 'UNKNOWN';
            debugging("Gemini File API: File state is {$state}", DEBUG_DEVELOPER);

            if ($state === 'ACTIVE') {
                return $filedata;
            }

            if ($state === 'FAILED') {
                throw new moodle_exception('ai_error', 'googlemeet', '', 'File processing failed');
            }

            // Wait before checking again.
            sleep(5);
        }

        throw new moodle_exception('ai_error', 'googlemeet', '', 'File processing timed out');
    }

    /**
     * Analyze a transcript text directly (fast path - no video processing needed).
     *
     * @param string $transcript The transcript text
     * @param string $videoname The video name for context
     * @param string $duration The video duration
     * @return stdClass Analysis result
     * @throws moodle_exception If analysis fails
     */
    public function analyze_transcript(string $transcript, string $videoname, string $duration): stdClass {
        if (!$this->is_configured()) {
            throw new moodle_exception('ai_not_configured', 'googlemeet');
        }

        debugging("Gemini API: Analyzing transcript for '{$videoname}'", DEBUG_DEVELOPER);

        $prompt = <<<PROMPT
You are an educational assistant analyzing a transcript from a recorded meeting/class.

Meeting Information:
- Title: {$videoname}
- Duration: {$duration}

Transcript:
{$transcript}

Based on this transcript, please provide the following in a structured JSON format:

1. **Summary**: A comprehensive summary of the meeting content (2-3 paragraphs)
2. **Key Points**: A list of 5-10 main takeaways or important points discussed
3. **Topics**: A list of main topics/themes covered
4. **Cleaned Transcript**: The transcript reformatted with proper punctuation and paragraph breaks

IMPORTANT: Respond ONLY with valid JSON in the following format (no markdown, no code blocks):
{
    "summary": "Your comprehensive summary here...",
    "keypoints": ["Point 1", "Point 2", "Point 3", ...],
    "topics": ["Topic 1", "Topic 2", "Topic 3", ...],
    "transcript": "The cleaned and formatted transcript...",
    "language": "detected language code (e.g., en, es, fr)"
}
PROMPT;

        $response = $this->call_api($prompt);
        debugging("Gemini API: Transcript analysis completed", DEBUG_DEVELOPER);

        return $this->parse_analysis_response($response);
    }

    /**
     * Analyze a video using its Gemini file URI.
     *
     * @param string $fileuri The Gemini file URI
     * @param string $mimetype The MIME type
     * @param string $videoname The video name for context
     * @param string $duration The video duration
     * @return stdClass Analysis result
     * @throws moodle_exception If analysis fails
     */
    public function analyze_video_with_file(string $fileuri, string $mimetype, string $videoname, string $duration): stdClass {
        if (!$this->is_configured()) {
            throw new moodle_exception('ai_not_configured', 'googlemeet');
        }

        debugging("Gemini API: Analyzing video with file URI: {$fileuri}", DEBUG_DEVELOPER);

        $url = self::API_BASE_URL . $this->model . ':generateContent?key=' . $this->apikey;

        $prompt = <<<PROMPT
You are an educational assistant analyzing a recorded meeting/class video.

Video Information:
- Title: {$videoname}
- Duration: {$duration}

Please analyze this video and provide the following in a structured JSON format:

1. **Summary**: A comprehensive summary of the video content (2-3 paragraphs)
2. **Key Points**: A list of 5-10 main takeaways or important points discussed
3. **Topics**: A list of main topics/themes covered in the video
4. **Transcript Summary**: Provide a condensed transcript of the main discussions

IMPORTANT: Respond ONLY with valid JSON in the following format (no markdown, no code blocks):
{
    "summary": "Your comprehensive summary here...",
    "keypoints": ["Point 1", "Point 2", "Point 3", ...],
    "topics": ["Topic 1", "Topic 2", "Topic 3", ...],
    "transcript": "Condensed transcript of the video...",
    "language": "detected language code (e.g., en, es, fr)"
}
PROMPT;

        $data = [
            'contents' => [
                [
                    'parts' => [
                        [
                            'fileData' => [
                                'mimeType' => $mimetype,
                                'fileUri' => $fileuri,
                            ]
                        ],
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.7,
                'topK' => 40,
                'topP' => 0.95,
                'maxOutputTokens' => 8192,
            ],
        ];

        $curl = new \curl();
        $curl->setHeader(['Content-Type: application/json']);

        $options = [
            'CURLOPT_TIMEOUT' => 300,        // 5 minutes for video analysis.
            'CURLOPT_CONNECTTIMEOUT' => 60,
        ];

        $response = $curl->post($url, json_encode($data), $options);
        $info = $curl->get_info();
        $httpcode = $info['http_code'] ?? 0;

        if ($httpcode !== 200) {
            $error = json_decode($response);
            $errormsg = isset($error->error->message) ? $error->error->message : "HTTP error {$httpcode}";
            debugging("Gemini API error: {$errormsg}", DEBUG_DEVELOPER);
            throw new moodle_exception('ai_error', 'googlemeet', '', $errormsg);
        }

        debugging("Gemini API: Video analysis completed successfully", DEBUG_DEVELOPER);

        return $this->parse_analysis_response($response);
    }

    /**
     * Delete a file from Gemini File API.
     *
     * @param string $filename The file name to delete
     * @return bool True if deleted successfully
     */
    public function delete_file(string $filename): bool {
        $url = self::FILE_API_URL . '/' . $filename . '?key=' . $this->apikey;

        $curl = new \curl();
        $curl->delete($url);
        $info = $curl->get_info();

        return $info['http_code'] === 200 || $info['http_code'] === 204;
    }

    /**
     * Get the API key (for use by other services that need to download from Drive).
     *
     * @return string
     */
    public function get_api_key(): string {
        return $this->apikey;
    }

}
