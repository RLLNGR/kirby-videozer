<?php

/**
 * Kirby Videozer
 *
 * On upload, compresses videos to H.264/MP4 + VP9/WebM and extracts a poster
 * frame using FFmpeg. All generated files are stored in {webroot}/video-cache/{page-id}/
 * — outside Kirby's content directory, served directly by the web server.
 *
 * Naming convention:
 *   {name}-poster.jpg      — poster frame
 *   {name}-compressed.mp4  — H.264/AAC copy
 *   {name}-opt.webm        — VP9/Opus copy
 *
 * @author  Nicolas Rollinger <https://rollinger.design>
 * @license MIT
 */

use Kirby\Cms\App;
use Kirby\Cms\File;
use Kirby\Filesystem\F;

F::loadClasses([
    'Rllngr\\Videozer\\Videozer' => __DIR__ . '/src/Videozer.php',
]);

App::plugin('rllngr/videozer', [
    'info' => [
        'version' => '1.1.0',
    ],

    'options' => [
        // Path to the ffmpeg binary. Defaults to 'ffmpeg' (resolved via PATH).
        'ffmpeg'             => 'ffmpeg',
        // Named quality preset to use by default (web/high/low). null = use individual options.
        'preset'             => null,
        // Built-in quality presets (can be extended or overridden in config).
        'presets'            => [
            'web'  => ['crf' => 23, 'max_width' => 1280, 'ffpreset' => 'slow'],
            'high' => ['crf' => 18, 'max_width' => 1920, 'ffpreset' => 'slow'],
            'low'  => ['crf' => 28, 'max_width' => 720,  'ffpreset' => 'fast'],
        ],
        // H.264 CRF quality (0–51, lower = better). Used when no preset is set.
        'crf'                => 28,
        // Maximum output width in pixels (aspect ratio preserved).
        'max_width'          => 1920,
        // Strip audio from the output (recommended for silent background videos).
        'strip_audio'        => false,
        // MP4 audio bitrate (ignored when strip_audio is true).
        'audio_bitrate'      => '96k',
        // Whether to also generate a VP9/WebM variant.
        'generate_webm'      => true,
        // WebM CRF quality.
        'webm_crf'           => 33,
        // WebM audio bitrate (ignored when strip_audio is true).
        'webm_audio_bitrate' => '64k',
        // Restrict processing to specific page templates (array). null = all pages.
        'templates'          => null,
        // File template to assign after processing. false = skip.
        'change_template'    => false,
        // Gallery field to clean up on file deletion (removes UUID). null = skip.
        'gallery_field'      => null,
        // Custom base directory for the video cache. null = {webroot}/video-cache/.
        'cache_dir'          => null,
    ],

    // ── Hooks ─────────────────────────────────────────────────────────────────

    'hooks' => [

        'file.create:after' => function (File $file) {
            try {
                if ($file->type() !== 'video') return;

                $vz = new \Rllngr\Videozer\Videozer();
                if (!$vz->matchesTemplate($file)) return;
                if (!$vz->isAvailable()) return;

                // Assign file blueprint (optional)
                $tpl = option('rllngr.videozer.change_template', false);
                if ($tpl) {
                    try {
                        kirby()->impersonate('kirby', function () use ($file, $tpl) {
                            $file->changeTemplate($tpl);
                        });
                    } catch (\Throwable $e) {
                        error_log('Videozer: changeTemplate error: ' . $e->getMessage());
                    }
                }

                // Auto-save orientation from video dimensions (ffprobe, fast)
                if (!$file->content()->get('orientation')->isNotEmpty()) {
                    try {
                        $orientation = $vz->detectOrientation($file);
                        kirby()->impersonate('kirby', function () use ($file, $orientation) {
                            $file->update(['orientation' => $orientation]);
                        });
                    } catch (\Throwable $e) {
                        error_log('Videozer: orientation detect error: ' . $e->getMessage());
                    }
                }

                $vz->processBackground($file);

            } catch (\Throwable $e) {
                error_log('Videozer: file.create:after error: ' . $e->getMessage());
            }
        },

        'file.replace:after' => function ($newFile, $oldFile = null) {
            try {
                $file = $newFile;
                if (!$file instanceof File) return;
                if ($file->type() !== 'video') return;

                $vz = new \Rllngr\Videozer\Videozer();
                if (!$vz->matchesTemplate($file)) return;
                if (!$vz->isAvailable()) return;

                // Re-detect orientation when file is replaced
                try {
                    $orientation = $vz->detectOrientation($file);
                    kirby()->impersonate('kirby', function () use ($file, $orientation) {
                        $file->update(['orientation' => $orientation]);
                    });
                } catch (\Throwable $e) {
                    error_log('Videozer: orientation detect error: ' . $e->getMessage());
                }

                // Force re-process: replace cached files
                $vz->processBackground($file, null, true);

            } catch (\Throwable $e) {
                error_log('Videozer: file.replace:after error: ' . $e->getMessage());
            }
        },

        'file.delete:before' => function (File $file) {
            try {
                if ($file->type() !== 'video') return;

                $vz = new \Rllngr\Videozer\Videozer();
                if (!$vz->matchesTemplate($file)) return;

                // Remove UUID from gallery field (optional)
                // Workaround: Kirby can fail with "page slug is required" when a
                // draft (changes) version exists — pre-removing prevents that error.
                $galleryField = option('rllngr.videozer.gallery_field');
                if ($galleryField) {
                    $uuid    = $file->uuid()->toString();
                    $current = $file->parent()->content()->get($galleryField)->yaml();
                    $updated = array_values(array_filter($current, fn($v) => $v !== $uuid));
                    if (count($updated) < count($current)) {
                        kirby()->impersonate('kirby', function () use ($file, $galleryField, $updated) {
                            $file->parent()->update([$galleryField => $updated]);
                        });
                    }
                }

                // Delete cached files
                $vz->deleteCached($file);

            } catch (\Throwable $e) {
                error_log('Videozer: file.delete:before error: ' . $e->getMessage());
            }
        },

    ],

    // ── File methods ───────────────────────────────────────────────────────────

    'fileMethods' => [

        // Best available MP4 URL: compressed if cached, original otherwise.
        /** @kql-allowed */
        'videozUrl' => function (): string {
            $vz = new \Rllngr\Videozer\Videozer();
            return $vz->hasMp4($this)
                ? $vz->mp4Url($this)
                : $this->url();
        },

        // Best available WebM URL, or null if not generated.
        /** @kql-allowed */
        'videozWebmUrl' => function (): ?string {
            $vz = new \Rllngr\Videozer\Videozer();
            return $vz->hasWebm($this) ? $vz->webmUrl($this) : null;
        },

        // Poster URL. Always returns the expected URL — the browser handles 404 via @error.
        // Avoids file_exists() returning false due to filesystem/cache timing issues.
        /** @kql-allowed */
        'videozPosterUrl' => function (): ?string {
            if ($this->type() !== 'video') return null;
            return (new \Rllngr\Videozer\Videozer())->posterUrl($this);
        },

        // Srcset for the poster frame using Kirby's thumb system (via content-dir copy).
        // Falls back to null if the poster hasn't been extracted yet.
        /** @kql-allowed */
        'videozPosterSrcset' => function (): ?string {
            if ($this->type() !== 'video') return null;
            $posterFile = $this->parent()->image($this->name() . '-poster.jpg');
            return $posterFile ? $posterFile->srcset() : null;
        },

        // Whether a compressed MP4 exists in the cache.
        /** @kql-allowed */
        'hasVideoz' => function (): bool {
            return (new \Rllngr\Videozer\Videozer())->hasMp4($this);
        },

        // Whether a poster exists in the cache.
        /** @kql-allowed */
        'videozHasPoster' => function (): bool {
            return (new \Rllngr\Videozer\Videozer())->hasPoster($this);
        },

        // Returns the image to display in the panel for image.query.
        // For videos: returns the poster frame (copied to content dir by videozer).
        // For images: returns the file itself so Kirby shows the image preview.
        // Returns null for other types (Kirby shows default icon).
        'videozPanelImage' => function (): ?\Kirby\Cms\File {
            if ($this->type() === 'video') {
                $posterFilename = $this->name() . '-poster.jpg';
                return $this->parent()->image($posterFilename);
            }
            if ($this->type() === 'image') {
                return $this;
            }
            return null;
        },

        // Orientation string ('portrait'|'landscape'|'square').
        // Returns the user-set panel value if present, otherwise auto-detects:
        // - Images: from Kirby's built-in width/height
        // - Videos: from ffprobe (fast metadata-only read, ~100ms)
        /** @kql-allowed */
        'videozOrientation' => function (): string {
            $stored = $this->content()->get('orientation')->value();
            if ($stored) return $stored;

            if ($this->type() === 'image') {
                return \Rllngr\Videozer\Videozer::orientationFromDimensions(
                    (int) $this->width(),
                    (int) $this->height()
                );
            }

            if ($this->type() === 'video') {
                $vz   = new \Rllngr\Videozer\Videozer();
                $info = $vz->getVideoInfo($this);
                return \Rllngr\Videozer\Videozer::orientationFromDimensions(
                    (int) ($info['width'] ?? 0),
                    (int) ($info['height'] ?? 0)
                );
            }

            return 'landscape';
        },

        // FFprobe metadata for this video.
        'videozInfo' => function (): ?array {
            return (new \Rllngr\Videozer\Videozer())->getVideoInfo($this);
        },

        // Manually trigger (or force re-)processing.
        'videozerProcess' => function (?string $preset = null, bool $force = false): void {
            (new \Rllngr\Videozer\Videozer())->process($this, $preset, $force);
        },

        // Manually trigger (or force re-)poster generation.
        'videozGeneratePoster' => function (bool $force = false, ?float $timestamp = null): void {
            (new \Rllngr\Videozer\Videozer())->generatePoster($this, $force, $timestamp);
        },

    ],

    // ── Page method ────────────────────────────────────────────────────────────

    'pageMethods' => [
        // Files on this page excluding any videozer-generated variants.
        // (Generated files live in video-cache/, so they normally don't appear
        //  in $page->files() — but this guard handles any edge case.)
        'videozFiles' => function () {
            return $this->files()->filter(function ($file) {
                return !preg_match('/(-compressed\.mp4|-opt\.webm|-poster\.jpg)$/', $file->filename());
            });
        },
    ],

    // ── Panel API routes (authenticated) ───────────────────────────────────────

    'api' => [
        'routes' => [

            // GET /api/videozer/status
            [
                'pattern' => 'videozer/status',
                'method'  => 'GET',
                'action'  => function () {
                    $vz = new \Rllngr\Videozer\Videozer();

                    $total = $pending = $processed = 0;
                    foreach (kirby()->site()->index() as $page) {
                        foreach ($page->files()->filterBy('type', 'video') as $file) {
                            $total++;
                            if ($vz->hasMp4($file)) {
                                $processed++;
                            } else {
                                $pending++;
                            }
                        }
                    }

                    return [
                        'ffmpeg'    => $vz->getFfmpegPath(),
                        'available' => $vz->isAvailable(),
                        'presets'   => option('rllngr.videozer.presets'),
                        'videos'    => [
                            'total'     => $total,
                            'processed' => $processed,
                            'pending'   => $pending,
                        ],
                    ];
                },
            ],

            // POST /api/videozer/optimize  (body: page, filename, preset, force)
            [
                'pattern' => 'videozer/optimize',
                'method'  => 'POST',
                'action'  => function () {
                    $pageId   = get('page');
                    $filename = get('filename');
                    $preset   = get('preset');
                    $force    = (bool) get('force', false);

                    if (!$pageId || !$filename) {
                        return ['status' => 'error', 'message' => 'Missing page or filename'];
                    }

                    $page = kirby()->page($pageId);
                    if (!$page) return ['status' => 'error', 'message' => 'Page not found'];

                    $file = $page->file($filename);
                    if (!$file) return ['status' => 'error', 'message' => 'File not found'];

                    try {
                        $vz = new \Rllngr\Videozer\Videozer();
                        $vz->process($file, $preset ?: null, $force);
                        return [
                            'status'   => 'success',
                            'original' => $file->url(),
                            'mp4'      => $vz->hasMp4($file) ? $vz->mp4Url($file) : null,
                            'webm'     => $vz->hasWebm($file) ? $vz->webmUrl($file) : null,
                            'poster'   => $vz->hasPoster($file) ? $vz->posterUrl($file) : null,
                        ];
                    } catch (\Exception $e) {
                        return ['status' => 'error', 'message' => $e->getMessage()];
                    }
                },
            ],

            // POST /api/videozer/optimize-all  (body: preset, force)
            [
                'pattern' => 'videozer/optimize-all',
                'method'  => 'POST',
                'action'  => function () {
                    $preset = get('preset');
                    $force  = (bool) get('force', false);

                    $vz = new \Rllngr\Videozer\Videozer();
                    if (!$vz->isAvailable()) {
                        return ['status' => 'error', 'message' => 'FFmpeg is not available'];
                    }

                    $results = [];
                    foreach (kirby()->site()->index() as $page) {
                        foreach ($page->files()->filterBy('type', 'video') as $file) {
                            if (!$vz->matchesTemplate($file)) continue;

                            try {
                                $vz->process($file, $preset ?: null, $force);
                                $results[] = [
                                    'status'   => 'success',
                                    'page'     => $page->id(),
                                    'file'     => $file->filename(),
                                ];
                            } catch (\Exception $e) {
                                $results[] = [
                                    'status'  => 'error',
                                    'page'    => $page->id(),
                                    'file'    => $file->filename(),
                                    'message' => $e->getMessage(),
                                ];
                            }
                        }
                    }

                    return [
                        'status'  => 'complete',
                        'total'   => count($results),
                        'success' => count(array_filter($results, fn($r) => $r['status'] === 'success')),
                        'errors'  => count(array_filter($results, fn($r) => $r['status'] === 'error')),
                        'results' => $results,
                    ];
                },
            ],

        ],
    ],

]);
