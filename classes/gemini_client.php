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
use mod_googlemeet\gemini_transient_exception;

// curl class lives in lib/filelib.php and is not autoloaded — load it eagerly
// so adhoc/scheduled task contexts (which don't go through code that pulls
// filelib.php) can still instantiate \curl.
global $CFG;
require_once($CFG->libdir . '/filelib.php');

/**
 * Gemini API Client for mod_googlemeet.
 *
 * Handles communication with Google's Gemini AI API for generating
 * video summaries, key points, and transcripts.
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
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

    /** @var string The default primary model when none is configured */
    private const DEFAULT_MODEL = 'gemini-3.5-flash';

    /** @var string Fallback model when primary fails */
    private const FALLBACK_MODEL = 'gemini-3.1-flash-lite';

    /** @var bool Whether AI features are enabled */
    private $enabled;

    /** @var string|null The model actually used by the last successful API call */
    private $lastusedmodel = null;

    /** @var model_policy The model selection / fallback policy. */
    private $modelpolicy;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->enabled = (bool) get_config('googlemeet', 'enableai');
        $this->apikey = get_config('googlemeet', 'geminiapikey');
        $this->model = get_config('googlemeet', 'aimodel') ?: self::DEFAULT_MODEL;

        // The model/fallback policy (primary configured model → FALLBACK_MODEL).
        // Centralising it here keeps the fallback decision in one cohesive,
        // extensible place; the request/parse logic below merely follows the
        // attempt chain it produces.
        $this->modelpolicy = new model_policy($this->model, self::FALLBACK_MODEL);
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
     * Get the model that was actually used by the most recent successful API call.
     *
     * Because the client transparently falls back to {@see self::FALLBACK_MODEL}
     * when the configured model fails, the configured model returned by
     * {@see self::get_model()} may not reflect what actually produced a result.
     * Callers persisting the model (e.g. the `aimodel` column) should use this
     * value after a successful call so the stored model is accurate.
     *
     * @return string|null The effective model, or null if no successful call has been made.
     */
    public function get_last_used_model(): ?string {
        return $this->lastusedmodel;
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
     * Generate single-answer multichoice questions from a transcript.
     *
     * @param string $transcript Transcript text.
     * @param int $count Number of questions requested.
     * @param string $lang Target language code.
     * @return array
     * @throws moodle_exception If generation or parsing fails.
     */
    public function generate_questions(string $transcript, int $count, string $lang): array {
        if (!$this->is_configured()) {
            throw new moodle_exception('ai_not_configured', 'googlemeet');
        }
        if (trim($transcript) === '') {
            throw new moodle_exception('question_no_transcript_error', 'googlemeet');
        }

        $count = max(1, min(20, $count));
        $prompt = <<<PROMPT
You are an expert teacher creating exam-practice questions from a class transcript.

Write in this language whenever possible: {$lang}.
Focus only on educational, assessable content. Ignore greetings, admin chatter, and off-topic discussion.
Create {$count} single-answer multiple-choice questions.

Return STRICT JSON only, no markdown and no code fences. The top-level value MUST be an array. Each item MUST have:
{
  "stem": "question text",
  "options": ["answer A", "answer B", "answer C", "answer D"],
  "correctindex": 0,
  "explanation": "why the correct answer is correct",
  "citation": "article/norm/topic or timestamp mm:ss, or null"
}

Rules:
- options must contain exactly 4 plausible strings.
- correctindex must be an integer from 0 to 3.
- explanation must justify the answer.
- citation should identify the reference, topic, regulation, article, or class timestamp when available; use null if not detected.

Transcript:
{$transcript}
PROMPT;

        $response = $this->call_api($prompt);
        return $this->parse_questions_response($response);
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

CRITICAL RULE: You MUST write the summary, keypoints, topics, and transcript in the SAME language as the video/audio content. If the video is in Spanish, your entire response MUST be in Spanish. NEVER translate to English or any other language.

Video Information:
- Title: {$videoname}
- Duration: {$duration}
- URL: {$videourl}

Please analyze this video and provide the following in a structured JSON format:

1. **Summary**: A comprehensive summary of the video content (2-3 paragraphs) — in the language of the video
2. **Key Points**: A list of 5-10 main takeaways or important points discussed — in the language of the video
3. **Topics**: 3 to 6 SHORT study tags in the same language as the video. Each topic MUST be 1-3 words where possible and at most 40 characters. Do NOT write full sentences, procedural descriptions, or long legal headings — produce compact chip labels (e.g. "Caducidad", "LPAC", "Procedimiento sancionador"). No duplicates or near-duplicates. Put any detailed description in the summary or keypoints, never in topics.
4. **Transcript Summary**: If audio is available, provide a condensed transcript of the main discussions — in the language of the video

IMPORTANT: Respond ONLY with valid JSON in the following format (no markdown, no code blocks):
{
    "summary": "Resumen completo aquí (en el idioma del vídeo)...",
    "keypoints": ["Punto 1", "Punto 2", "Punto 3", ...],
    "topics": ["Caducidad", "LPAC", "Procedimiento sancionador"],
    "transcript": "Transcripción condensada o 'No disponible'...",
    "language": "detected language code (e.g., es, en, fr)"
}

If you cannot access the video, analyze based on the title and provide your best interpretation, noting that direct video analysis was not possible.
PROMPT;
    }

    /**
     * Call the Gemini API with automatic fallback to a secondary model.
     *
     * If the primary model fails with an API error (4xx/5xx), retries
     * with the fallback model before throwing.
     *
     * @param string $prompt The prompt to send
     * @return string The API response
     * @throws moodle_exception If both primary and fallback API calls fail
     */
    private function call_api(string $prompt): string {
        $chain = $this->modelpolicy->get_attempt_chain();
        $lastexception = null;
        foreach ($chain as $model) {
            try {
                return $this->call_api_with_model($prompt, $model);
            } catch (moodle_exception $e) {
                $lastexception = $e;
                // If the policy offers a further model, log the fallback and
                // continue; otherwise rethrow the failure to the caller.
                if (!$this->modelpolicy->has_fallback_after($model)) {
                    throw $e;
                }
                $this->log_fallback($model, $e);
            }
        }
        // Unreachable in practice (the chain is never empty and the loop either
        // returns or rethrows), but kept for completeness/static analysis.
        throw $lastexception;
    }

    /**
     * Log a model fallback in a visible way.
     *
     * Fallbacks change which model actually produced the result, so they are
     * surfaced via mtrace() (visible in cron/task output) in addition to a
     * developer-level debugging() message.
     *
     * @param string $failedmodel The configured model that failed.
     * @param \Throwable $e The exception that triggered the fallback.
     * @return void
     */
    private function log_fallback(string $failedmodel, \Throwable $e): void {
        $message = "Gemini: model '{$failedmodel}' failed ({$e->getMessage()}); "
            . "falling back to '" . $this->modelpolicy->get_fallback() . "'";
        if (function_exists('mtrace')) {
            mtrace($message);
        }
        debugging($message, DEBUG_DEVELOPER);
    }

    /**
     * Call the Gemini API with a specific model.
     *
     * @param string $prompt The prompt to send
     * @param string $model The model name to use
     * @return string The API response
     * @throws moodle_exception If the API call fails
     */
    private function call_api_with_model(string $prompt, string $model): string {
        // The API key is sent in the x-goog-api-key header (never in the URL/query
        // string) so it cannot leak through a logged or thrown URL.
        $url = self::API_BASE_URL . $model . ':generateContent';

        debugging("Gemini API: Starting request to {$url}", DEBUG_DEVELOPER);

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
        $curl->setHeader([
            'Content-Type: application/json',
            'x-goog-api-key: ' . $this->apikey,
        ]);

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

        // Validate HTTP status and classify transient vs permanent errors.
        $this->handle_http_response($httpcode, $response, $model);

        // Record the model that actually produced this successful response.
        $this->lastusedmodel = $model;

        return $response;
    }

    /**
     * Validate an HTTP response and translate error statuses into exceptions.
     *
     * Shared by the text-generation and video-analysis paths. On a non-200
     * status it throws either a {@see gemini_transient_exception} (for
     * retryable errors such as rate limits / overload) or a moodle_exception.
     * Returns cleanly on HTTP 200.
     *
     * @param int $httpcode The HTTP status code.
     * @param string $response The raw response body.
     * @param string $model The model used for this request (for logging context).
     * @return void
     * @throws gemini_transient_exception If the error is transient/retryable.
     * @throws moodle_exception If the error is permanent.
     */
    private function handle_http_response(int $httpcode, string $response, string $model): void {
        // No response at all (network failure not flagged as a curl errno).
        if ($httpcode === 0) {
            debugging("Gemini API: No response received", DEBUG_DEVELOPER);
            throw new moodle_exception('ai_error', 'googlemeet', '', 'No response from API - check network connectivity');
        }

        if ($httpcode === 200) {
            return;
        }

        $error = json_decode($response);
        $errormsg = isset($error->error->message) ? $error->error->message : "HTTP error {$httpcode}";
        debugging("Gemini API error ({$model}): {$errormsg}", DEBUG_DEVELOPER);

        $transient_patterns = ['high demand', 'overloaded', 'RESOURCE_EXHAUSTED', 'quota', 'rate limit', 'try again'];
        $is_transient = in_array($httpcode, [429, 500, 503], true);
        if (!$is_transient) {
            foreach ($transient_patterns as $p) {
                if (stripos($errormsg, $p) !== false) {
                    $is_transient = true;
                    break;
                }
            }
        }
        if ($is_transient) {
            throw new gemini_transient_exception($errormsg);
        }
        throw new moodle_exception('ai_error', 'googlemeet', '', $errormsg);
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

        // A non-parseable model response is a hard failure: persisting an empty
        // analysis would silently hide the problem. Throw so the caller/task
        // marks the analysis as failed instead.
        if (!is_object($analysis)) {
            debugging("Gemini API: model response was not valid JSON", DEBUG_DEVELOPER);
            throw new moodle_exception('ai_invalid_analysis', 'googlemeet', '', json_last_error_msg());
        }

        // The summary is the core required field; without it the analysis is
        // meaningless. Other fields are optional and may legitimately be empty.
        $summary = $analysis->summary ?? '';
        if (!is_string($summary) || trim($summary) === '') {
            throw new moodle_exception('ai_invalid_analysis', 'googlemeet', '', 'Missing summary in analysis response');
        }

        // Ensure all fields exist with sane types.
        $result = new stdClass();
        $result->summary = $summary;
        $result->keypoints = is_array($analysis->keypoints ?? null) ? $analysis->keypoints : [];
        $result->topics = is_array($analysis->topics ?? null) ? $analysis->topics : [];
        $result->transcript = is_string($analysis->transcript ?? null) ? $analysis->transcript : '';
        $result->language = is_string($analysis->language ?? null) ? $analysis->language : 'es';

        return $result;
    }

    /**
     * Parse Gemini question-generation JSON.
     *
     * @param string $response Raw API response.
     * @return array
     * @throws moodle_exception
     */
    private function parse_questions_response(string $response): array {
        $decoded = json_decode($response);

        if (!$decoded || !isset($decoded->candidates[0]->content->parts[0]->text)) {
            throw new moodle_exception('ai_error', 'googlemeet', '', 'Invalid API response format');
        }

        $text = trim($decoded->candidates[0]->content->parts[0]->text);
        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/', $text, $matches)) {
            $text = trim($matches[1]);
        }
        if (preg_match('/(\[[\s\S]*\])/', $text, $matches)) {
            $text = trim($matches[1]);
        }

        $items = json_decode($text, true);
        if (!is_array($items)) {
            throw new moodle_exception('ai_invalid_analysis', 'googlemeet', '', json_last_error_msg());
        }

        $questions = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $stem = trim((string)($item['stem'] ?? ''));
            $options = array_values($item['options'] ?? []);
            $correctindex = $item['correctindex'] ?? null;
            if ($stem === '' || count($options) !== 4 || !is_numeric($correctindex)) {
                continue;
            }
            $correctindex = (int)$correctindex;
            if ($correctindex < 0 || $correctindex > 3) {
                continue;
            }
            $cleanoptions = [];
            foreach ($options as $option) {
                $option = trim((string)$option);
                if ($option === '') {
                    continue 2;
                }
                $cleanoptions[] = $option;
            }
            $questions[] = [
                'stem' => $stem,
                'options' => $cleanoptions,
                'correctindex' => $correctindex,
                'explanation' => trim((string)($item['explanation'] ?? '')),
                'citation' => isset($item['citation']) ? trim((string)$item['citation']) : null,
            ];
        }

        if (empty($questions)) {
            throw new moodle_exception('ai_invalid_analysis', 'googlemeet', '', 'No valid questions in response');
        }

        return $questions;
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

        // Start resumable upload. The API key travels in the x-goog-api-key
        // header (never the query string) so it cannot leak via a logged URL.
        $url = self::UPLOAD_URL;

        $curl = new \curl();
        $curl->setHeader([
            'x-goog-api-key: ' . $this->apikey,
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
            // Try to get from response headers differently. Moodle's \curl exposes the raw
            // header lines via get_raw_response() (an array), not get_raw_response_headers().
            $rawheaders = $curl->get_raw_response();
            $headerstr = is_array($rawheaders) ? implode("\n", $rawheaders) : (string) $rawheaders;
            if (preg_match('/x-goog-upload-url:\s*(.+)/i', $headerstr, $matches)) {
                $uploadurl = trim($matches[1]);
            }
        }

        if (!$uploadurl) {
            debugging("Gemini File API: No upload URL received", DEBUG_DEVELOPER);
            throw new moodle_exception('ai_error', 'googlemeet', '', 'No upload URL received from API');
        }

        debugging("Gemini File API: Got upload URL, uploading file content...", DEBUG_DEVELOPER);

        // Upload the actual file content as a stream so the whole video (up to
        // ~2GB) is never loaded into PHP memory. We hand cURL the open file
        // handle via CURLOPT_INFILE/CURLOPT_INFILESIZE with CURLOPT_UPLOAD, and
        // force the HTTP verb to POST (CURLOPT_UPLOAD would otherwise use PUT)
        // to match the resumable-upload protocol.
        $fp = fopen($filepath, 'rb');
        if ($fp === false) {
            throw new moodle_exception('ai_error', 'googlemeet', '', 'Could not open video file for upload');
        }

        // We deliberately use a native cURL handle here instead of Moodle's \curl wrapper:
        // \curl::post() always sets CURLOPT_POST + CURLOPT_POSTFIELDS, which overrides the
        // CURLOPT_INFILE read stream and would upload an empty body. A raw handle with
        // CURLOPT_UPLOAD/CURLOPT_INFILE streams the file straight from disk, so the full
        // video (up to ~2GB) is never loaded into PHP memory. The upload URL is returned by
        // Google's File API (trusted), so the SSRF protections of \curl are not needed here.
        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $uploadurl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_UPLOAD => true,
                CURLOPT_INFILE => $fp,
                CURLOPT_INFILESIZE => $filesize,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_TIMEOUT => 600,          // 10 minutes for large files.
                CURLOPT_CONNECTTIMEOUT => 60,
                CURLOPT_HTTPHEADER => [
                    'x-goog-api-key: ' . $this->apikey,
                    'X-Goog-Upload-Offset: 0',
                    'X-Goog-Upload-Command: upload, finalize',
                ],
            ]);

            $response = curl_exec($ch);
            $httpcode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlerror = curl_errno($ch) ? curl_error($ch) : '';
            curl_close($ch);
        } finally {
            fclose($fp);
        }

        if ($curlerror !== '') {
            debugging("Gemini File API: Upload transport error: {$curlerror}", DEBUG_DEVELOPER);
            throw new moodle_exception('ai_error', 'googlemeet', '', 'Failed to upload file content');
        }

        if ($httpcode !== 200) {
            debugging("Gemini File API: Upload failed, HTTP " . $httpcode, DEBUG_DEVELOPER);
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
        // Key sent via header, never in the URL/query string.
        $url = self::FILE_API_URL . '/' . $filename;
        $starttime = time();

        debugging("Gemini File API: Waiting for file {$filename} to be processed...", DEBUG_DEVELOPER);

        while (time() - $starttime < $maxwait) {
            $curl = new \curl();
            $curl->setHeader(['x-goog-api-key: ' . $this->apikey]);
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
    public function analyze_transcript(string $transcript, string $videoname = '', string $duration = ''): stdClass {
        if (!$this->is_configured()) {
            throw new moodle_exception('ai_not_configured', 'googlemeet');
        }

        if (empty(trim($transcript))) {
            throw new moodle_exception('ai_error', 'googlemeet', '', 'Transcript is empty');
        }

        debugging("Gemini API: Analyzing transcript for '{$videoname}'", DEBUG_DEVELOPER);

        // Build context info only if we have data.
        $contextinfo = '';
        if (!empty($videoname) || !empty($duration)) {
            $contextinfo = "Meeting Information:\n";
            if (!empty($videoname)) {
                $contextinfo .= "- Title: {$videoname}\n";
            }
            if (!empty($duration)) {
                $contextinfo .= "- Duration: {$duration}\n";
            }
            $contextinfo .= "\n";
        }

        $prompt = <<<PROMPT
You are an educational assistant analyzing a class transcript.

CRITICAL RULE: You MUST write the summary, keypoints, and topics in the SAME language as the transcript. If the transcript is in Spanish, your entire response (summary, keypoints, topics) MUST be in Spanish. If the transcript is in English, respond in English. NEVER translate to a different language.

Focus ONLY on educational content and curriculum topics. Ignore any casual conversation, greetings, small talk, holiday wishes, off-topic discussions, or informal chat.

{$contextinfo}Transcript:
{$transcript}

Based ONLY on the educational content, provide in JSON format:

1. **Summary**: Summary of the educational content covered (2-3 paragraphs) — MUST be in the same language as the transcript
2. **Key Points**: 5-10 key learning points from the lesson — MUST be in the same language as the transcript
3. **Topics**: 3 to 6 SHORT study tags in the same language as the transcript. Each topic MUST be 1-3 words where possible and at most 40 characters. Do NOT write full sentences, procedural descriptions, or long legal headings — produce compact chip labels (e.g. "Caducidad", "LPAC", "Procedimiento sancionador"). No duplicates or near-duplicates. Put any detailed description in the summary or keypoints, never in topics.
4. **Language**: Detect the language of the transcript (ISO 639-1 code: es, en, pt, fr, de, etc.)

Respond ONLY with valid JSON (no markdown):
{
    "summary": "Resumen educativo aquí (en el idioma de la transcripción)...",
    "keypoints": ["Punto clave 1", "Punto clave 2", ...],
    "topics": ["Caducidad", "LPAC", "Procedimiento sancionador"],
    "language": "es"
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

        $chain = $this->modelpolicy->get_attempt_chain();
        $lastexception = null;
        foreach ($chain as $model) {
            try {
                return $this->analyze_video_with_file_using_model($fileuri, $mimetype, $videoname, $duration, $model);
            } catch (moodle_exception $e) {
                $lastexception = $e;
                if (!$this->modelpolicy->has_fallback_after($model)) {
                    throw $e;
                }
                debugging("Gemini model {$model} failed for video analysis: " . $e->getMessage()
                    . ". Falling back to " . $this->modelpolicy->get_fallback(), DEBUG_DEVELOPER);
            }
        }
        // Unreachable in practice (chain non-empty; loop returns or rethrows).
        throw $lastexception;
    }

    /**
     * Analyze a video using a specific model.
     *
     * @param string $fileuri The Gemini file URI
     * @param string $mimetype The MIME type
     * @param string $videoname The video name for context
     * @param string $duration The video duration
     * @param string $model The model to use
     * @return stdClass Analysis result
     * @throws moodle_exception If analysis fails
     */
    private function analyze_video_with_file_using_model(
        string $fileuri, string $mimetype, string $videoname, string $duration, string $model
    ): stdClass {

        debugging("Gemini API: Analyzing video with file URI: {$fileuri} using model: {$model}", DEBUG_DEVELOPER);

        // Key sent via the x-goog-api-key header, never in the URL/query string.
        $url = self::API_BASE_URL . $model . ':generateContent';

        $prompt = <<<PROMPT
You are an educational assistant analyzing a recorded meeting/class video.

CRITICAL RULE: You MUST write the summary, keypoints, topics, and transcript in the SAME language as the video/audio content. If the video is in Spanish, your entire response MUST be in Spanish. NEVER translate to English or any other language.

Video Information:
- Title: {$videoname}
- Duration: {$duration}

Please analyze this video and provide the following in a structured JSON format:

1. **Summary**: A comprehensive summary of the video content (2-3 paragraphs) — in the language of the video
2. **Key Points**: A list of 5-10 main takeaways or important points discussed — in the language of the video
3. **Topics**: 3 to 6 SHORT study tags in the same language as the video. Each topic MUST be 1-3 words where possible and at most 40 characters. Do NOT write full sentences, procedural descriptions, or long legal headings — produce compact chip labels (e.g. "Caducidad", "LPAC", "Procedimiento sancionador"). No duplicates or near-duplicates. Put any detailed description in the summary or keypoints, never in topics.
4. **Transcript Summary**: Provide a condensed transcript of the main discussions — in the language of the video

IMPORTANT: Respond ONLY with valid JSON in the following format (no markdown, no code blocks):
{
    "summary": "Resumen completo aquí (en el idioma del vídeo)...",
    "keypoints": ["Punto 1", "Punto 2", "Punto 3", ...],
    "topics": ["Caducidad", "LPAC", "Procedimiento sancionador"],
    "transcript": "Transcripción condensada del vídeo...",
    "language": "detected language code (e.g., es, en, fr)"
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
        $curl->setHeader([
            'Content-Type: application/json',
            'x-goog-api-key: ' . $this->apikey,
        ]);

        $options = [
            'CURLOPT_TIMEOUT' => 300,        // 5 minutes for video analysis.
            'CURLOPT_CONNECTTIMEOUT' => 60,
        ];

        $response = $curl->post($url, json_encode($data), $options);
        $info = $curl->get_info();
        $httpcode = $info['http_code'] ?? 0;

        // Validate HTTP status and classify transient vs permanent errors.
        $this->handle_http_response($httpcode, $response, $model);

        debugging("Gemini API: Video analysis completed successfully with model {$model}", DEBUG_DEVELOPER);

        // Record the model that actually produced this successful response.
        $this->lastusedmodel = $model;

        return $this->parse_analysis_response($response);
    }

    /**
     * Delete a file from Gemini File API.
     *
     * @param string $filename The file name to delete
     * @return bool True if deleted successfully
     */
    public function delete_file(string $filename): bool {
        // Key sent via header, never in the URL/query string.
        $url = self::FILE_API_URL . '/' . $filename;

        $curl = new \curl();
        $curl->setHeader(['x-goog-api-key: ' . $this->apikey]);
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
