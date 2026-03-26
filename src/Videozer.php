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
        return $this->cacheDir($file) . '/' . $file->name() . '-poster.jpg';
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
        return $this->cacheUrl($file) . '/' . $file->name() . '-poster.jpg';
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

    // ── Main processing ────────────────────────────────────────────────────────

    /**
     * Process a video file: compress MP4, optionally generate WebM, extract poster.
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
                    . ' -vf "scale=min(%d\,iw):-2" %s -movflags +faststart %s 2>&1',
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
        if (option('rllngr.videozer.generate_webm', true)) {
            $webmFinal = $this->webmPath($file);
            if ($force || !file_exists($webmFinal)) {
                $webmAudio = $stripAudio
                    ? '-an'
                    : '-c:a libopus -b:a ' . option('rllngr.videozer.webm_audio_bitrate', '64k');
                $webmCrf = (int) option('rllngr.videozer.webm_crf', 33);
                $tmp = $webmFinal . '.tmp';
                $cmd = sprintf(
                    '%s -y -i %s -c:v libvpx-vp9 -b:v 0 -crf %d -vf "scale=min(%d\,iw):-2" %s %s 2>&1',
                    escapeshellcmd($ffmpeg),
                    escapeshellarg($inputPath),
                    $webmCrf,
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
        }

        // 3) Poster frame
        $this->generatePoster($file, $force);
    }

    /**
     * Extract a poster frame from the video.
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

        $maxWidth = (int) option('rllngr.videozer.max_width', 1920);
        $tmp      = $posterPath . '.tmp.jpg';
        $cmd      = sprintf(
            '%s -ss %.2f -y -i %s -vframes 1 -vf "scale=min(%d\,iw):-2" -q:v 2 -update 1 %s 2>&1',
            escapeshellcmd($this->ffmpegPath),
            $timestamp,
            escapeshellarg($file->root()),
            $maxWidth,
            escapeshellarg($tmp)
        );
        exec($cmd, $out, $ret);
        $this->log("poster: code={$ret} | " . implode(' ', array_slice($out, -3)));
        if ($ret === 0 && file_exists($tmp)) {
            @unlink($posterPath);
            rename($tmp, $posterPath);
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

    // ── Cache cleanup ──────────────────────────────────────────────────────────

    public function deleteCached(File $file): void
    {
        foreach ([$this->mp4Path($file), $this->webmPath($file), $this->posterPath($file)] as $path) {
            if (file_exists($path)) {
                @unlink($path);
                $this->log("Deleted: {$path}");
            }
        }

        $cacheDir = $this->cacheDir($file);
        if (is_dir($cacheDir) && count(glob($cacheDir . '/*')) === 0) {
            @rmdir($cacheDir);
        }
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
