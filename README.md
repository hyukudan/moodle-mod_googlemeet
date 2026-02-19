# Google Meet™ for Moodle

The Google Meet™ for Moodle plugin allows teachers to create Google Meet rooms directly from Moodle and share meeting recordings stored in Google Drive with students.

> **Note:** This is a fork of [ronefel/moodle-mod_googlemeet](https://github.com/ronefel/moodle-mod_googlemeet) with modernizations and improvements for Moodle 4.x.

## Features

### Core Features
- **Create Google Meet rooms** directly from Moodle without leaving the platform
- **Schedule sessions** with support for recurring events (daily, weekly)
- **Exclusion periods** to skip sessions during holidays (Christmas, Easter, etc.)
- **Cancelled sessions** marking for individual dates with optional reason
- **Upcoming events cards** showing session status (Live, Starting soon, Scheduled, Cancelled)
- **Configurable event limit** to control how many upcoming events are displayed
- **Recording management** with card-based UI for easy access to meeting recordings
- **Google Drive integration** to sync and display recordings automatically
- **Visibility controls** for teachers to show/hide recordings from students
- **Student notifications** before scheduled sessions
- **Calendar integration** with Moodle calendar events
- **Mobile app support** for Moodle mobile app (Ionic 5+)

### Recording Management
- **Custom recording filter** to specify the exact name pattern to search in Google Drive
- **Sync feedback** showing how many recordings were added, updated, or removed
- **Pagination** with configurable recordings per page
- **Sorting options** (newest/oldest first)

### AI-Powered Features (Gemini)
- **Automatic subtitle extraction** from Google Drive recordings (no video download needed)
- **AI Video Analysis** using Google Gemini to automatically analyze meeting recordings
- **Manual transcript analysis** - paste Google Meet transcripts and analyze with Gemini
- **Automatic summaries** generated from video content (educational content only, ignores small talk)
- **Key points extraction** highlighting the most important learning points
- **Topic tags** for quick content identification
- **Auto language detection** - summaries generated in the same language as the content
- **Preview cards** showing AI summary and topics directly in recording list
- **Teacher-only generation** - professors generate analysis once, students view without API calls
- **Background processing** with scheduled task for queued analyses
- **CLI bulk processing** for batch transcript extraction and analysis
- **Default model: Gemini 3 Flash Preview** (latest Google AI model)

## Requirements

- Moodle 4.0 or higher
- PHP 7.4 or higher

## Installation

1. Copy this plugin to the `mod/googlemeet` folder on the server
2. Login as administrator
3. Go to Site Administrator > Notifications
4. Install the plugin
5. Configure OAuth 2 service for Google (see below)

## OAuth 2 Configuration

To create Google Meet rooms from Moodle, you need an active OAuth 2 service for Google.

[Learn how to create Client ID and Client Secret](https://github.com/ronefel/moodle-mod_googlemeet/wiki/How-to-create-Client-ID-and-Client-Secret)

## Usage

### Creating a Google Meet activity

1. Turn editing on in your course
2. Add an activity > Google Meet
3. Enter the meeting name and configure options
4. Save the activity

### Managing recordings

1. Open the Google Meet activity
2. Login with your Google account (if not already logged in)
3. Click "Sync with Google Drive" to fetch recordings
4. Use the visibility toggle to show/hide recordings from students

### Configuring AI Features

1. Get a free Gemini API key from [Google AI Studio](https://aistudio.google.com/app/apikey)
2. Go to Site Administration > Plugins > Activity modules > Google Meet
3. Enable AI features and enter your API key
4. Select your preferred model (Flash for speed, Pro for quality)
5. Optionally enable auto-generation for new recordings

### Using AI Analysis

1. Open a Google Meet activity with synced recordings
2. Click the expand button (▼) on any recording to view AI analysis
3. Teachers can click "Generate AI Analysis" to create analysis from video
4. Alternatively, teachers can click the edit icon and paste a Google Meet transcript to analyze
5. Students will see the summary preview directly in the recording card
6. Click on the preview or expand button to see the full analysis
7. Use "Copy" to copy the transcript to clipboard

### CLI Bulk Processing

Extract subtitles and run AI analysis for all recordings in one command:

```bash
# Requires yt-dlp: curl -sL https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -o /tmp/yt-dlp && chmod +x /tmp/yt-dlp

# Process all recordings for a Google Meet activity
php admin/cli/process_transcripts.php --googlemeetid=1

# Dry run (preview without changes)
php admin/cli/process_transcripts.php --googlemeetid=1 --dry-run

# Process a single recording
php admin/cli/process_transcripts.php --googlemeetid=1 --recordingid=4

# Extract subtitles only (skip Gemini analysis)
php admin/cli/process_transcripts.php --googlemeetid=1 --skip-gemini
```

The CLI script extracts Google Drive's auto-generated subtitles (~200KB) instead of downloading the full video (~1GB), making it much faster and lighter.

## Changes in this fork

### Version 2.8.0
- **Automatic subtitle extraction** from Google Drive recordings using yt-dlp
  - Extracts auto-generated captions without downloading the full video
  - 3-tier analysis: existing transcript → subtitle extraction → video upload (fallback)
  - New `subtitle_extractor` class for reusable subtitle fetching
- **CLI bulk processing** (`cli/process_transcripts.php`) for batch operations
  - Process all recordings in a Google Meet activity with one command
  - Supports dry-run, single recording, and skip-gemini modes
- **Requires**: [yt-dlp](https://github.com/yt-dlp/yt-dlp) for subtitle extraction

### Version 2.7.4
- **Manual transcript analysis** - paste Google Meet transcripts and analyze with Gemini
- **Updated default model** to Gemini 3 Flash Preview (December 2025)
- **Educational content focus** - AI ignores small talk and focuses on curriculum
- **Auto language detection** - analysis in the same language as the transcript
- **Improved UX** - chevron expand icon instead of star, solid blue theme colors

### Version 2.7.3
- **Custom recording filter** - specify text pattern to match recordings in Google Drive
- **Sync feedback** - shows count of added, updated, and removed recordings
- **Manual AI editing** - teachers can manually enter/edit summaries, key points, and topics
- **Transcript section** hidden from students (teachers only)

### Version 2.7.0
- **Recordings pagination** with configurable items per page
- **Sorting options** (newest/oldest first)
- **Improved disk space management** for video processing

### Version 2.5.0 (AI Features)
- **Added AI-powered video analysis** using Google Gemini API
- Generate summaries, key points, topics, and transcripts from recordings
- Beautiful preview cards with AI content directly in recording list
- Background task processing for asynchronous analysis
- Teacher-only generation with student viewing (no API calls for students)

### Version 2.4.0
- **Added cancelled sessions feature** for individual date cancellations with optional reason
- **Added configurable maximum upcoming events** setting
- Cancelled sessions display with visual indicator and reason

### Version 2.3.0
- **Added exclusion periods** for recurring events (skip holidays like Christmas, Easter)

### Previous versions
- Modernized codebase for Moodle 4.0+ compatibility
- Removed deprecated Ionic 3 mobile support
- Removed deprecated `core-course-module-description` component
- Replaced deprecated `notice()` and `insert_records()` functions
- Removed insecure `unserialize()` usage
- Removed legacy logging system (now uses events)
- Updated minimum requirements to Moodle 4.0
- Fixed play button styling in recordings

## Security

If you discover any security related issues, please use the [GitHub issue tracker](https://github.com/hyukudan/moodle-mod_googlemeet/issues).

## Credits

**Original author:**
- Rone Santos <ronefel@hotmail.com> - [ronefel/moodle-mod_googlemeet](https://github.com/ronefel/moodle-mod_googlemeet)

**Fork maintainer:**
- [hyukudan](https://github.com/hyukudan)

**Development assistance:**
- Code modernization and improvements completed with the assistance of [Claude](https://claude.ai) (Anthropic)

## License

The GNU GENERAL PUBLIC LICENSE. Please see [License File](LICENSE.md) for more information.

---

> ©2018 Google LLC All rights reserved.
> Google Meet and the Google Meet logo are registered trademarks of Google LLC.
