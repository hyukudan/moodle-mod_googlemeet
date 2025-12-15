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

### AI-Powered Features (NEW)
- **AI Video Analysis** using Google Gemini to automatically analyze meeting recordings
- **Automatic summaries** generated from video content
- **Key points extraction** highlighting the most important topics discussed
- **Topic tags** for quick content identification
- **Transcript generation** with copy-to-clipboard functionality
- **Preview cards** showing AI summary and topics directly in recording list
- **Teacher-only generation** - professors generate analysis once, students view without API calls
- **Background processing** with scheduled task for queued analyses

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
2. Click the AI button (star icon) on any recording
3. Teachers can click "Generate AI Analysis" to create analysis
4. Students will see the summary preview directly in the recording card
5. Click on the preview or AI button to expand the full analysis
6. Use "Copy" to copy the transcript to clipboard

## Changes in this fork

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

If you discover any security related issues, please email [ronefel@hotmail.com](mailto:ronefel@hotmail.com) instead of using the issue tracker.

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
