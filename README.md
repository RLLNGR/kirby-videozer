# Kirby Videozer

A [Kirby CMS](https://getkirby.com) plugin that automatically compresses uploaded videos using FFmpeg and generates poster frames. All processed files are stored in a `video-cache/` directory outside Kirby's content folder and served directly by the web server.

**What it produces for each uploaded video:**
- `{name}-compressed.mp4` — H.264/AAC optimized MP4
- `{name}-opt.webm` — VP9/Opus WebM variant (optional)
- `{name}-poster.jpg` — Thumbnail extracted at 1 second (or 10% of duration)

The poster is also copied to the page's content directory so Kirby's thumb system can generate srcset variants and the Panel can show a video preview image. The video's orientation (`portrait`/`landscape`/`square`) is auto-detected from its dimensions and saved as a content field on upload.

---

## Requirements

- Kirby CMS ^4 || ^5
- PHP >=8.0
- [FFmpeg](https://ffmpeg.org) installed on the server

---

## Installation

### Via Composer (recommended)

```bash
composer require rllngr/kirby-videozer
```

### Manual

Download and extract the plugin into `site/plugins/videozer/`.

---

## Configuration

Add options to your `site/config/config.php`:

```php
return [
    // Path to the ffmpeg binary. Defaults to 'ffmpeg' (resolved via PATH).
    'rllngr.videozer.ffmpeg' => '/usr/local/bin/ffmpeg',

    // Named quality preset to use (web/high/low). Overrides crf/max_width when set.
    // Default: null (uses individual crf/max_width options below)
    'rllngr.videozer.preset' => 'web',

    // Built-in presets — override or extend as needed.
    'rllngr.videozer.presets' => [
        'web'  => ['crf' => 23, 'max_width' => 1280, 'ffpreset' => 'slow'],
        'high' => ['crf' => 18, 'max_width' => 1920, 'ffpreset' => 'slow'],
        'low'  => ['crf' => 28, 'max_width' => 720,  'ffpreset' => 'fast'],
    ],

    // H.264 CRF quality (0–51, lower = better quality). Used when no preset is set.
    'rllngr.videozer.crf' => 28,

    // Maximum output width in pixels (aspect ratio preserved). Default: 1920
    'rllngr.videozer.max_width' => 1920,

    // Strip audio from the output. Recommended for silent background/portfolio videos.
    // Default: false
    'rllngr.videozer.strip_audio' => false,

    // MP4 audio bitrate. Ignored when strip_audio is true. Default: '96k'
    'rllngr.videozer.audio_bitrate' => '96k',

    // Whether to generate a VP9/WebM variant. Default: true
    'rllngr.videozer.generate_webm' => true,

    // WebM CRF quality. Default: 33
    'rllngr.videozer.webm_crf' => 33,

    // WebM audio bitrate. Ignored when strip_audio is true. Default: '64k'
    'rllngr.videozer.webm_audio_bitrate' => '64k',

    // Restrict processing to specific page templates. null = all pages.
    'rllngr.videozer.templates' => ['project', 'article'],

    // File template to assign after processing. false = skip.
    'rllngr.videozer.change_template' => 'video',

    // Name of the gallery field to clean up on file deletion. null = skip.
    'rllngr.videozer.gallery_field' => 'gallery',

    // Custom base directory for the video cache. null = {webroot}/video-cache/.
    'rllngr.videozer.cache_dir' => null,
];
```

---

## Usage

### Page method

Use `$page->videozFiles()` to retrieve files excluding any generated variants (useful for gallery fields):

```php
// In a blueprint field:
query: page.videozFiles

// In a template:
foreach ($page->videozFiles() as $file) { ... }
```

### File methods

All methods are available directly on video file objects:

```php
// Best available MP4 URL (compressed if cached, original otherwise)
$file->videozUrl()

// WebM URL if generated, null otherwise
$file->videozWebmUrl()

// Poster URL — always returns the expected URL (browser handles 404 gracefully via @error)
$file->videozPosterUrl()

// Srcset for the poster frame via Kirby's thumb system (requires content-dir copy to exist)
$file->videozPosterSrcset()

// Whether a compressed MP4 exists in the cache
$file->hasVideoz()

// Whether a poster exists in the cache
$file->videozHasPoster()

// Orientation string: 'portrait', 'landscape', or 'square'
// Returns the saved panel value if set, otherwise auto-detects from ffprobe/image dimensions
$file->videozOrientation()

// Panel image for use with `image.query` — returns poster for videos, self for images, null otherwise
$file->videozPanelImage()

// FFprobe metadata: duration, size, bitrate, width, height, codec, fps, hasAudio
$file->videozInfo()

// Manually trigger processing (optional preset + force flag)
$file->videozerProcess('high', force: true)

// Manually regenerate poster (optional force + timestamp in seconds)
$file->videozGeneratePoster(force: true, timestamp: 3.5)
```

### Cache URL pattern

Generated files are stored at:

```
/video-cache/{page-id}/{name}-compressed.mp4
/video-cache/{page-id}/{name}-opt.webm
/video-cache/{page-id}/{name}-poster.jpg
```

Make sure your web server serves this directory statically. Example nginx rule:

```nginx
location /video-cache/ {
    root /path/to/public;
    expires 30d;
    add_header Cache-Control "public";
}
```

### Panel API routes

The plugin exposes three authenticated Panel API endpoints:

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET`  | `/api/videozer/status` | FFmpeg availability + video stats |
| `POST` | `/api/videozer/optimize` | Process a single file (`page`, `filename`, `preset`, `force`) |
| `POST` | `/api/videozer/optimize-all` | Batch process all videos (`preset`, `force`) |

---

## Troubleshooting

- Check `site/plugins/videozer/videozer.log` for processing errors.
- If FFmpeg is not found, set `rllngr.videozer.ffmpeg` to the full binary path.
- Uploads trigger `processBackground()` — FFmpeg runs in a detached shell so the Panel request returns immediately. The poster and srcset will appear once the background job completes.
- The API routes (`/api/videozer/optimize`, `/api/videozer/optimize-all`) use the synchronous `process()` method — suited for scripts and manual re-processing.

---

## License

MIT — [Nicolas Rollinger](https://rollinger.design) 🫰
