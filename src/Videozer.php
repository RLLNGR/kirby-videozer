<?php

namespace Rllngr\Videozer;

use Kirby\Cms\File;

class Videozer
{
    protected ?string $ffmpegPath = null;

    public function __construct()
    {
        $this->ffmpegPath = $this->detectFfmpeg();
    }

    // ── Availability ───────────────────────────────────────────────────────────

    public function isAvailable(): bool
    {
        return $this->ffmpegPath !== null;
    }

    public function getFfmpegPath(): ?string
    {
        return $this->ffmpegPath;
    }

    protected function detectFfmpeg(): ?string
    {
        $configured = option('rllngr.videozer.ffmpeg', 'ffmpeg');

        $candidates = array_unique([
            $configured,
            '/opt/homebrew/bin/ffmpeg',
            '/usr/local/bin/ffmpeg',
            '/usr/bin/ffmpeg',
            'ffmpeg',
        ]);

        foreach ($candidates as $path) {
            if (empty($path)) continue;

            if (strpos($path, '/') !== false) {
                if (is_executable($path)) return $path;
            } else {
                // Resolve via PATH
                $which = trim((string) shell_exec('command -v ' . escapeshellarg($path) . ' 2>/dev/null'));
                if ($which && is_executable($which)) return $which;
            }
        }

        return null;
    }

    // ── Config / presets ───────────────────────────────────────────────────────

    protected function getConfig(?string $preset): array
    {
        $presets     = option('rllngr.videozer.presets', []);
        $defaultName = option('rllngr.videozer.preset');
        $name        = $preset ?? $defaultName;

        if ($name && isset($presets[$name])) {
            return array_merge([
                'crf'       => 28,
                'max_width' => 1920,
                'ffpreset'  => 'slow',
            ], $presets[$name]);
        }

        return [
            'crf'       => (int) option('rllngr.videozer.crf', 28),
            'max_width' => (int) option('rllngr.videozer.max_width', 1920),
            'ffpreset'  => 'slow',
        ];
    }

    // ── Cache paths / URLs ─────────────────────────────────────────────────────

    public function cacheDir(File $file): string
    {
        $custom = option('rllngr.videozer.cache_dir');
        if ($custom) {
            return rtrim($custom, '/') . '/' . $file->parent()->id();
        }
        return kirby()->root('index') . '/video-cache/' . $file->parent()->id();
    }

    public function cacheUrl(File $file): string
    {
        return rtrim(kirby()->url(), '/') . '/video-cache/' . $file->parent()->id();
    }

    public function mp4Path(File $file): string
    {
        return $this->cacheDir($file) . '/' . $file->name() . '-compressed.mp4';
    }

    public function webmPath(File $file): string
    {
        return $this->cacheDir($file) . '/' . $file->name() . '-opt.webm';
    }

    public function posterPath(File $file): string
    {
        return $this->cacheDir($file) . '/' . $file->name() . '-poster.' . $this->posterExtension();
    }

    protected function posterExtension(): string
    {
        if (option('rllngr.videozer.alpha_support', false)) return 'png';
        return option('rllngr.videozer.poster_format', 'jpg');
    }

    public function mp4Url(File $file): string
    {
        return $this->cacheUrl($file) . '/' . $file->name() . '-compressed.mp4';
    }

    public function webmUrl(File $file): string
    {
        return $this->cacheUrl($file) . '/' . $file->name() . '-opt.webm';
    }

    public function posterUrl(File $file): string
    {
        return $this->cacheUrl($file) . '/' . $file->name() . '-poster.' . $this->posterExtension();
    }

    public function hasMp4(File $file): bool
    {
        return file_exists($this->mp4Path($file));
    }

    public function hasWebm(File $file): bool
    {
        return file_exists($this->webmPath($file));
    }

    public function hasPoster(File $file): bool
    {
        return file_exists($this->posterPath($file));
    }

    public function hevcPath(File $file): string
    {
        return $this->cacheDir($file) . '/' . $file->name() . '-hevc.mov';
    }

    public function hevcUrl(File $file): string
    {
        return $this->cacheUrl($file) . '/' . $file->name() . '-hevc.mov';
    }

    public function hasHevc(File $file): bool
    {
        return file_exists($this->hevcPath($file));
    }

    public function hevcStackedPath(File $file): string
    {
        return $this->cacheDir($file) . '/' . $file->name() . '-hevc-stacked.mp4';
    }

    public function hevcStackedUrl(File $file): string
    {
        return $this->cacheUrl($file) . '/' . $file->name() . '-hevc-stacked.mp4';
    }

    public function hasHevcStacked(File $file): bool
    {
        return file_exists($this->hevcStackedPath($file));
    }

    public function av1Path(File $file): string
    {
        return $this->cacheDir($file) . '/' . $file->name() . '-av1-stacked.mp4';
    }

    public function av1Url(File $file): string
    {
        return $this->cacheUrl($file) . '/' . $file->name() . '-av1-stacked.mp4';
    }

    public function hasAv1(File $file): bool
    {
        return file_exists($this->av1Path($file));
    }

    public function lastFramePath(File $file): string
    {
        return $this->cacheDir($file) . '/' . $file->name() . '-last.' . $this->posterExtension();
    }

    public function lastFrameUrl(File $file): string
    {
        return $this->cacheUrl($file) . '/' . $file->name() . '-last.' . $this->posterExtension();
    }

    public function hasLastFrame(File $file): bool
    {
        return file_exists($this->lastFramePath($file));
    }

    // ── Main processing ────────────────────────────────────────────────────────

    /**
     * Process a video file: compress MP4, optionally generate WebM, extract poster.
     * Runs synchronously (blocking). Use processBackground() from hooks to avoid
     * blocking the panel upload request.
     *
     * @param File        $file   The original video file
     * @param string|null $preset Named quality preset (web/high/low) or null for defaults
     * @param bool        $force  Re-process even if cached files already exist
     */
    public function process(File $file, ?string $preset = null, bool $force = false): void
    {
        if (!$this->isAvailable()) {
            throw new \Exception('FFmpeg is not available');
        }

        $config     = $this->getConfig($preset);
        $cacheDir   = $this->cacheDir($file);
        $inputPath  = $file->root();
        $ffmpeg     = $this->ffmpegPath;
        $stripAudio = option('rllngr.videozer.strip_audio', false);
        $audioOpts  = $stripAudio
            ? '-an'
            : '-c:a aac -b:a ' . option('rllngr.videozer.audio_bitrate', '96k');

        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        // 1) Compressed MP4 (H.264/AAC)
        $mp4Final = $this->mp4Path($file);
        if ($force || !file_exists($mp4Final)) {
            $tmp = $mp4Final . '.tmp';
            $cmd = sprintf(
                '%s -y -i %s -c:v libx264 -preset %s -crf %d -profile:v high -level 4.0'
                    . ' -vf "scale=min(%d\,iw):-2" %s -movflags +faststart -f mp4 %s 2>&1',
                escapeshellcmd($ffmpeg),
                escapeshellarg($inputPath),
                $config['ffpreset'],
                $config['crf'],
                $config['max_width'],
                $audioOpts,
                escapeshellarg($tmp)
            );
            exec($cmd, $out, $ret);
            $this->log("mp4: code={$ret} | " . implode(' ', array_slice($out, -3)));
            if ($ret === 0 && file_exists($tmp)) {
                @unlink($mp4Final);
                rename($tmp, $mp4Final);
            } else {
                @unlink($tmp);
                throw new \Exception('MP4 conversion failed: ' . implode("\n", array_slice($out, -5)));
            }
        }

        // 2) WebM (VP9/Opus) — optional
        // Skip re-encoding when the source IS already a WebM with alpha channel.
        // The source file is already in the correct format for browsers —
        // videozWebmUrl returns $file->url() in this case.
        // Use -vcodec libvpx-vp9 (software decoder) to preserve BlockAdditional alpha
        // when needed for HEVC/poster/last-frame generation.
        // For MOV with alpha (Apple HEVC alpha): generate a WebM VP9 with alpha for Chrome/Firefox.
        $sourceIsAlphaWebm = strtolower($file->extension()) === 'webm' && $this->hasAlpha($file);
        $sourceIsAlphaMov  = strtolower($file->extension()) === 'mov'  && $this->hasAlpha($file);
        $decoderFlag       = $sourceIsAlphaWebm ? '-vcodec libvpx-vp9' : '';

        if (option('rllngr.videozer.generate_webm', true) && !$sourceIsAlphaWebm) {
            $webmFinal = $this->webmPath($file);
            if ($force || !file_exists($webmFinal)) {
                $webmAudio = $stripAudio
                    ? '-an'
                    : '-c:a libopus -b:a ' . option('rllngr.videozer.webm_audio_bitrate', '64k');
                $webmCrf    = (int) option('rllngr.videozer.webm_crf', 33);
                $alphaFlags = $this->hasAlpha($file) ? '-pix_fmt yuva420p -auto-alt-ref 0' : '';
                $tmp = $webmFinal . '.tmp';
                $cmd = sprintf(
                    '%s -y -i %s -c:v libvpx-vp9 -b:v 0 -crf %d %s -vf "scale=min(%d\,iw):-2" %s -f webm %s 2>&1',
                    escapeshellcmd($ffmpeg),
                    escapeshellarg($inputPath),
                    $webmCrf,
                    $alphaFlags,
                    $config['max_width'],
                    $webmAudio,
                    escapeshellarg($tmp)
                );
                exec($cmd, $out, $ret);
                $this->log("webm: code={$ret} | " . implode(' ', array_slice($out, -3)));
                if ($ret === 0 && file_exists($tmp)) {
                    @unlink($webmFinal);
                    rename($tmp, $webmFinal);
                } else {
                    @unlink($tmp);
                }
            }
        } elseif ($sourceIsAlphaWebm) {
            $this->log("webm: skipped — source is WebM with alpha, serving original directly");
        }

        // 3) HEVC stacked (libx265, cross-platform) — optional, WebM sources only
        // Encodes colour+alpha as a double-height video (colour on top, greyscale alpha on bottom).
        // Replaces the old hevc_videotoolbox approach which was macOS-only and silently dropped alpha.
        // alpha_support:true auto-enables this; generate_hevc_stacked:true also triggers it.
        // Always use -vcodec libvpx-vp9 decoder for WebM so BlockAdditional alpha is preserved.
        $stackedFilter = '[0:v]format=pix_fmts=yuva444p[rgba];[rgba]split[color][amask];[amask]alphaextract[alpha];[color][alpha]vstack';
        $wantsHevcStacked = option('rllngr.videozer.alpha_support', false) || option('rllngr.videozer.generate_hevc_stacked', false);
        if ($wantsHevcStacked && strtolower($file->extension()) === 'webm') {
            $hevcStackedFinal = $this->hevcStackedPath($file);
            if ($force || !file_exists($hevcStackedFinal)) {
                $tmp = $hevcStackedFinal . '.tmp';
                $cmd = sprintf(
                    '%s -y -vcodec libvpx-vp9 -i %s -filter_complex "%s" -pix_fmt yuv420p -c:v libx265 -preset slow -crf 28 -tag:v hvc1 -movflags +faststart -an -f mp4 %s 2>&1',
                    escapeshellcmd($ffmpeg),
                    escapeshellarg($inputPath),
                    $stackedFilter,
                    escapeshellarg($tmp)
                );
                exec($cmd, $out, $ret);
                $this->log("hevc-stacked: code={$ret} | " . implode(' ', array_slice($out, -3)));
                if ($ret === 0 && file_exists($tmp)) {
                    @unlink($hevcStackedFinal);
                    rename($tmp, $hevcStackedFinal);
                } else {
                    @unlink($tmp);
                }
            }
        }

        // 3b) AV1 stacked (libaom-av1) — optional, WebM sources only
        // Primary format for Safari 16+, Chrome, Firefox. Same stacked-alpha encoding.
        $wantsAv1 = option('rllngr.videozer.generate_av1_stacked', false);
        if ($wantsAv1 && strtolower($file->extension()) === 'webm') {
            $av1Final = $this->av1Path($file);
            if ($force || !file_exists($av1Final)) {
                $tmp = $av1Final . '.tmp';
                $cmd = sprintf(
                    '%s -y -vcodec libvpx-vp9 -i %s -filter_complex "%s" -pix_fmt yuv420p -c:v libaom-av1 -cpu-used 4 -crf 45 -movflags +faststart -an -f mp4 %s 2>&1',
                    escapeshellcmd($ffmpeg),
                    escapeshellarg($inputPath),
                    $stackedFilter,
                    escapeshellarg($tmp)
                );
                exec($cmd, $out, $ret);
                $this->log("av1-stacked: code={$ret} | " . implode(' ', array_slice($out, -3)));
                if ($ret === 0 && file_exists($tmp)) {
                    @unlink($av1Final);
                    rename($tmp, $av1Final);
                } else {
                    @unlink($tmp);
                }
            }
        }

        // 4) Poster frame + last frame
        $this->generatePoster($file, $force);
        $this->generateLastFrame($file, $force);
    }

    /**
     * Process a video asynchronously: fires all FFmpeg commands in a detached background shell
     * and returns immediately. Use from hooks so the panel upload request doesn't time out.
     * The API route uses process() for synchronous/trackable processing.
     *
     * @param File        $file   The original video file
     * @param string|null $preset Named quality preset or null for defaults
     * @param bool        $force  Re-process even if cached files already exist
     */
    public function processBackground(File $file, ?string $preset = null, bool $force = false): void
    {
        if (!$this->isAvailable()) return;

        $config     = $this->getConfig($preset);
        $cacheDir   = $this->cacheDir($file);
        $inputPath  = $file->root();
        $ffmpeg     = escapeshellcmd($this->ffmpegPath);
        $stripAudio = option('rllngr.videozer.strip_audio', false);
        $audioOpts  = $stripAudio
            ? '-an'
            : '-c:a aac -b:a ' . option('rllngr.videozer.audio_bitrate', '96k');
        $logFile    = escapeshellarg(dirname(__DIR__) . '/videozer.log');

        // Create cache directory now (synchronous, fast)
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        $parts = [];

        // 1) Compressed MP4
        $mp4Final = $this->mp4Path($file);
        if ($force || !file_exists($mp4Final)) {
            $tmp = escapeshellarg($mp4Final . '.tmp');
            $out = escapeshellarg($mp4Final);
            $parts[] = sprintf(
                '%s -y -i %s -c:v libx264 -preset %s -crf %d -profile:v high -level 4.0'
                    . ' -vf "scale=min(%d\,iw):-2" %s -movflags +faststart -f mp4 %s'
                    . ' && mv -f %s %s',
                $ffmpeg, escapeshellarg($inputPath),
                $config['ffpreset'], $config['crf'], $config['max_width'],
                $audioOpts, $tmp, $tmp, $out
            );
        }

        // 2) WebM (VP9) — optional
        // Skip for sources that are already WebM with alpha (served directly as-is).
        // For MOV with alpha (Apple HEVC alpha): generate a WebM VP9 with alpha for Chrome/Firefox.
        $sourceIsAlphaWebm = strtolower($file->extension()) === 'webm' && $this->hasAlpha($file);
        $sourceIsAlphaMov  = strtolower($file->extension()) === 'mov'  && $this->hasAlpha($file);
        $decoderFlag       = $sourceIsAlphaWebm ? '-vcodec libvpx-vp9' : '';

        if (option('rllngr.videozer.generate_webm', true) && !$sourceIsAlphaWebm) {
            $webmFinal = $this->webmPath($file);
            if ($force || !file_exists($webmFinal)) {
                $webmCrf    = (int) option('rllngr.videozer.webm_crf', 33);
                $webmAudio  = $stripAudio
                    ? '-an'
                    : '-c:a libopus -b:a ' . option('rllngr.videozer.webm_audio_bitrate', '64k');
                $alphaFlags = $this->hasAlpha($file) ? '-pix_fmt yuva420p -auto-alt-ref 0' : '';
                $tmp = escapeshellarg($webmFinal . '.tmp');
                $out = escapeshellarg($webmFinal);
                $parts[] = sprintf(
                    '%s -y -i %s -c:v libvpx-vp9 -b:v 0 -crf %d %s -vf "scale=min(%d\,iw):-2" %s -f webm %s'
                        . ' && mv -f %s %s',
                    $ffmpeg, escapeshellarg($inputPath),
                    $webmCrf, $alphaFlags, $config['max_width'],
                    $webmAudio, $tmp, $tmp, $out
                );
            }
        } elseif ($sourceIsAlphaWebm) {
            $this->log("webm: skipped — source is WebM with alpha, serving original directly");
        }

        // 3) HEVC stacked (libx265, cross-platform) — optional, WebM sources only
        // Always use libvpx-vp9 decoder to ensure BlockAdditional alpha is preserved.
        $stackedFilter = '[0:v]format=pix_fmts=yuva444p[rgba];[rgba]split[color][amask];[amask]alphaextract[alpha];[color][alpha]vstack';
        $wantsHevcStacked = option('rllngr.videozer.alpha_support', false) || option('rllngr.videozer.generate_hevc_stacked', false);
        if ($wantsHevcStacked && strtolower($file->extension()) === 'webm') {
            $hevcStackedFinal = $this->hevcStackedPath($file);
            if ($force || !file_exists($hevcStackedFinal)) {
                $tmp = escapeshellarg($hevcStackedFinal . '.tmp');
                $out = escapeshellarg($hevcStackedFinal);
                $parts[] = sprintf(
                    '%s -y -vcodec libvpx-vp9 -i %s -filter_complex "%s" -pix_fmt yuv420p -c:v libx265 -preset slow -crf 28 -tag:v hvc1 -movflags +faststart -an -f mp4 %s'
                        . ' && mv -f %s %s',
                    $ffmpeg, escapeshellarg($inputPath), $stackedFilter, $tmp, $tmp, $out
                );
            }
        }

        // 3b) AV1 stacked (libaom-av1) — optional, WebM sources only
        $wantsAv1 = option('rllngr.videozer.generate_av1_stacked', false);
        if ($wantsAv1 && strtolower($file->extension()) === 'webm') {
            $av1Final = $this->av1Path($file);
            if ($force || !file_exists($av1Final)) {
                $tmp = escapeshellarg($av1Final . '.tmp');
                $out = escapeshellarg($av1Final);
                $parts[] = sprintf(
                    '%s -y -vcodec libvpx-vp9 -i %s -filter_complex "%s" -pix_fmt yuv420p -c:v libaom-av1 -cpu-used 4 -crf 45 -movflags +faststart -an -f mp4 %s'
                        . ' && mv -f %s %s',
                    $ffmpeg, escapeshellarg($inputPath), $stackedFilter, $tmp, $tmp, $out
                );
            }
        }

        // 4) Poster frame (use fixed 1s timestamp to avoid needing ffprobe)
        // Also copy to the page's content directory so Kirby can use it as a panel preview image.
        // For alpha WebM: use libvpx-vp9 software decoder to preserve transparency.
        $posterPath    = $this->posterPath($file);
        $ext           = $this->posterExtension();
        $contentPoster = escapeshellarg($file->parent()->root() . '/' . $file->name() . '-poster.' . $ext);
        if ($force || !file_exists($posterPath)) {
            $maxWidth                   = (int) option('rllngr.videozer.max_width', 1920);
            [$qualityFlag, $formatFlag] = $ext === 'webp' ? ['-q:v 85', '-f webp'] : ['-q:v 2', '-f image2'];
            $scaleFilter = in_array($ext, ['png', 'webp'])
                ? "scale=min(%d\\,iw):-2,format=rgba"
                : "scale=min(%d\\,iw):-2";
            $tmp     = escapeshellarg($posterPath . '.tmp.' . $ext);
            $out     = escapeshellarg($posterPath);
            $parts[] = sprintf(
                '%s -ss 1.0 -y %s -i %s -vframes 1 -vf "' . $scaleFilter . '" %s -update 1 %s %s'
                    . ' && mv -f %s %s && cp -f %s %s',
                $ffmpeg, $decoderFlag, escapeshellarg($inputPath),
                $maxWidth, $qualityFlag, $formatFlag, $tmp, $tmp, $out, $out, $contentPoster
            );
        }

        // 5) Last frame (-sseof seek from end, no ffprobe needed)
        // For alpha WebM: use libvpx-vp9 software decoder to preserve transparency.
        $lastPath    = $this->lastFramePath($file);
        $contentLast = escapeshellarg($file->parent()->root() . '/' . $file->name() . '-last.' . $ext);
        if ($force || !file_exists($lastPath)) {
            $maxWidth                   = (int) option('rllngr.videozer.max_width', 1920);
            [$qualityFlag, $formatFlag] = $ext === 'webp' ? ['-q:v 85', '-f webp'] : ['-q:v 2', '-f image2'];
            $scaleFilter = in_array($ext, ['png', 'webp'])
                ? "scale=min(%d\\,iw):-2,format=rgba"
                : "scale=min(%d\\,iw):-2";
            $tmp     = escapeshellarg($lastPath . '.tmp.' . $ext);
            $out     = escapeshellarg($lastPath);
            $parts[] = sprintf(
                '%s -sseof -0.1 -y %s -i %s -vframes 1 -vf "' . $scaleFilter . '" %s -update 1 %s %s'
                    . ' && mv -f %s %s && cp -f %s %s',
                $ffmpeg, $decoderFlag, escapeshellarg($inputPath),
                $maxWidth, $qualityFlag, $formatFlag, $tmp, $tmp, $out, $out, $contentLast
            );
        }

        if (empty($parts)) {
            $this->log("processBackground: nothing to do for {$file->filename()}");
            return;
        }

        // Run all commands sequentially in a detached background shell.
        // nohup is not used — PHP-FPM has no controlling terminal and nohup fails
        // with "Inappropriate ioctl for device" in that context.
        $script = implode(' ; ', $parts);
        $bgCmd  = 'bash -c ' . escapeshellarg($script) . ' >> ' . $logFile . ' 2>&1 &';
        exec($bgCmd);

        $this->log("processBackground: queued {$file->filename()} (" . count($parts) . " steps)");
    }

    /**
     * Extract a poster frame from the video.
     * Also copies the poster to the page's content directory so Kirby's thumb
     * system can generate srcset variants and the panel can show a preview.
     *
     * @param File       $file      The original video file
     * @param bool       $force     Regenerate even if poster already exists
     * @param float|null $timestamp Timestamp in seconds (null = smart default)
     */
    public function generatePoster(File $file, bool $force = false, ?float $timestamp = null): void
    {
        if (!$this->isAvailable()) {
            throw new \Exception('FFmpeg is not available');
        }

        $posterPath = $this->posterPath($file);

        if (!$force && file_exists($posterPath)) return;

        $cacheDir = $this->cacheDir($file);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        if ($timestamp === null) {
            $info      = $this->getVideoInfo($file);
            $duration  = $info['duration'] ?? 0;
            $timestamp = $duration > 0 ? min(1.0, $duration * 0.1) : 1.0;
        }

        $maxWidth    = (int) option('rllngr.videozer.max_width', 1920);
        $ext         = $this->posterExtension();
        $tmp         = $posterPath . '.tmp.' . $ext;
        $decoderFlag = strtolower($file->extension()) === 'webm' && $this->hasAlpha($file)
            ? '-vcodec libvpx-vp9'
            : '';
        [$qualityFlag, $formatFlag] = $ext === 'webp'
            ? ['-q:v 85', '-f webp']
            : ['-q:v 2',  '-f image2'];
        $scaleFilter = in_array($ext, ['png', 'webp'])
            ? 'scale=min(%d\,iw):-2,format=rgba'
            : 'scale=min(%d\,iw):-2';
        $cmd = sprintf(
            '%s -ss %.2f -y %s -i %s -vframes 1 -vf "' . $scaleFilter . '" %s -update 1 %s %s 2>&1',
            escapeshellcmd($this->ffmpegPath),
            $timestamp,
            $decoderFlag,
            escapeshellarg($file->root()),
            $maxWidth,
            $qualityFlag,
            $formatFlag,
            escapeshellarg($tmp)
        );
        exec($cmd, $out, $ret);
        $this->log("poster: code={$ret} | " . implode(' ', array_slice($out, -3)));
        if ($ret === 0 && file_exists($tmp)) {
            @unlink($posterPath);
            rename($tmp, $posterPath);
            // Copy to page content directory for Kirby panel preview and srcset
            $contentPoster = $file->parent()->root() . '/' . $file->name() . '-poster.' . $ext;
            @copy($posterPath, $contentPoster);
        } else {
            @unlink($tmp);
        }
    }

    /**
     * Extract the last frame of the video.
     * Uses -sseof to seek from the end — no ffprobe required.
     * Also copies to the page content directory alongside the poster.
     */
    public function generateLastFrame(File $file, bool $force = false): void
    {
        if (!$this->isAvailable()) {
            throw new \Exception('FFmpeg is not available');
        }

        $lastPath = $this->lastFramePath($file);

        if (!$force && file_exists($lastPath)) return;

        $cacheDir = $this->cacheDir($file);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        $maxWidth    = (int) option('rllngr.videozer.max_width', 1920);
        $ext         = $this->posterExtension();
        $tmp         = $lastPath . '.tmp.' . $ext;
        $decoderFlag = strtolower($file->extension()) === 'webm' && $this->hasAlpha($file)
            ? '-vcodec libvpx-vp9'
            : '';
        [$qualityFlag, $formatFlag] = $ext === 'webp'
            ? ['-q:v 85', '-f webp']
            : ['-q:v 2',  '-f image2'];
        $scaleFilter = in_array($ext, ['png', 'webp'])
            ? 'scale=min(%d\,iw):-2,format=rgba'
            : 'scale=min(%d\,iw):-2';

        $cmd = sprintf(
            '%s -sseof -0.1 -y %s -i %s -vframes 1 -vf "' . $scaleFilter . '" %s -update 1 %s %s 2>&1',
            escapeshellcmd($this->ffmpegPath),
            $decoderFlag,
            escapeshellarg($file->root()),
            $maxWidth,
            $qualityFlag,
            $formatFlag,
            escapeshellarg($tmp)
        );
        exec($cmd, $out, $ret);
        $this->log("last-frame: code={$ret} | " . implode(' ', array_slice($out, -3)));
        if ($ret === 0 && file_exists($tmp)) {
            @unlink($lastPath);
            rename($tmp, $lastPath);
            $contentLast = $file->parent()->root() . '/' . $file->name() . '-last.' . $ext;
            @copy($lastPath, $contentLast);
        } else {
            @unlink($tmp);
        }
    }

    // ── Video metadata (FFprobe) ───────────────────────────────────────────────

    public function getVideoInfo(File $file): ?array
    {
        if (!$this->isAvailable()) return null;

        $ffprobePath = str_replace('ffmpeg', 'ffprobe', $this->ffmpegPath);
        $cmd = sprintf(
            '%s -v quiet -print_format json -show_format -show_streams %s 2>/dev/null',
            $ffprobePath,
            escapeshellarg($file->root())
        );

        $output = shell_exec($cmd);
        if (!$output) return null;

        $info = json_decode($output, true);
        if (!$info) return null;

        $videoStream = $audioStream = null;
        foreach ($info['streams'] ?? [] as $stream) {
            if ($stream['codec_type'] === 'video' && !$videoStream) $videoStream = $stream;
            if ($stream['codec_type'] === 'audio' && !$audioStream) $audioStream = $stream;
        }

        return [
            'duration' => (float) ($info['format']['duration'] ?? 0),
            'size'     => (int)   ($info['format']['size'] ?? 0),
            'bitrate'  => (int)   ($info['format']['bit_rate'] ?? 0),
            'width'    => (int)   ($videoStream['width'] ?? 0),
            'height'   => (int)   ($videoStream['height'] ?? 0),
            'codec'    => $videoStream['codec_name'] ?? 'unknown',
            'fps'      => $this->parseFps($videoStream['r_frame_rate'] ?? '0/1'),
            'hasAudio' => $audioStream !== null,
        ];
    }

    protected function parseFps(string $fps): float
    {
        $parts = explode('/', $fps);
        if (count($parts) === 2 && (int) $parts[1] !== 0) {
            return round((int) $parts[0] / (int) $parts[1], 2);
        }
        return (float) $fps;
    }

    // ── Orientation detection ──────────────────────────────────────────────────

    /**
     * Detect orientation string ('portrait'|'landscape'|'square') from pixel dimensions.
     */
    public static function orientationFromDimensions(int $width, int $height): string
    {
        if ($width <= 0 || $height <= 0) return 'landscape';
        $ratio = $width / $height;
        if ($ratio < 0.85) return 'portrait';
        if ($ratio > 1.15) return 'landscape';
        return 'square';
    }

    /**
     * Detect orientation for a video file using ffprobe.
     * Fast (reads metadata only, no decoding).
     */
    public function detectOrientation(File $file): string
    {
        $info = $this->getVideoInfo($file);
        return self::orientationFromDimensions(
            (int) ($info['width'] ?? 0),
            (int) ($info['height'] ?? 0)
        );
    }

    // ── Cache cleanup ──────────────────────────────────────────────────────────

    public function deleteCached(File $file): void
    {
        foreach ([$this->mp4Path($file), $this->webmPath($file), $this->hevcPath($file), $this->hevcStackedPath($file), $this->av1Path($file), $this->posterPath($file), $this->lastFramePath($file)] as $path) {
            if (file_exists($path)) {
                @unlink($path);
                $this->log("Deleted: {$path}");
            }
        }

        // Also remove content-directory copies (poster + last frame) and their Kirby meta .txt files
        $ext = $this->posterExtension();
        foreach (['-poster', '-last'] as $suffix) {
            $contentFile = $file->parent()->root() . '/' . $file->name() . $suffix . '.' . $ext;
            if (file_exists($contentFile)) @unlink($contentFile);
            if (file_exists($contentFile . '.txt')) @unlink($contentFile . '.txt');
        }

        $cacheDir = $this->cacheDir($file);
        if (is_dir($cacheDir) && count(glob($cacheDir . '/*')) === 0) {
            @rmdir($cacheDir);
        }
    }

    // ── Alpha detection ────────────────────────────────────────────────────────

    /**
     * Returns true if the source video has an alpha channel.
     * Detects VP9 alpha_mode, yuva pixel formats, and Apple HEVC alpha MOV
     * (which stores color and alpha as two separate video streams).
     */
    public function hasAlpha(File $file): bool
    {
        if (!$this->isAvailable()) return false;
        $ffprobePath = str_replace('ffmpeg', 'ffprobe', $this->ffmpegPath);
        $cmd = sprintf(
            '%s -v quiet -print_format json -show_streams %s 2>/dev/null',
            $ffprobePath,
            escapeshellarg($file->root())
        );
        $output = shell_exec($cmd);
        if (!$output) return false;
        $info = json_decode($output, true);

        $videoStreams = array_values(array_filter(
            $info['streams'] ?? [],
            fn($s) => $s['codec_type'] === 'video'
        ));

        // Apple HEVC alpha MOV: color and alpha are stored as two separate video streams
        if (strtolower($file->extension()) === 'mov' && count($videoStreams) >= 2) return true;

        foreach ($videoStreams as $stream) {
            // VP9 alpha flag
            if (($stream['tags']['alpha_mode'] ?? '') === '1') return true;
            // Pixel formats with alpha (yuva420p, yuva444p, ayuv64le, …)
            if (isset($stream['pix_fmt']) && str_contains($stream['pix_fmt'], 'a')) return true;
        }
        return false;
    }

    // ── Template filter ────────────────────────────────────────────────────────

    public function matchesTemplate(File $file): bool
    {
        $templates = option('rllngr.videozer.templates');
        if (empty($templates)) return true;
        $page = $file->parent();
        if (!$page) return false;
        return in_array($page->intendedTemplate()->name(), (array) $templates, true);
    }

    // ── Logging ────────────────────────────────────────────────────────────────

    protected function log(string $message): void
    {
        @file_put_contents(
            dirname(__DIR__) . '/videozer.log',
            date('c') . ' ' . $message . PHP_EOL,
            FILE_APPEND
        );
    }
}
