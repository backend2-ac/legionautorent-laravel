<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;

class BuildHeroFrames extends Command
{
    protected $signature = 'hero:build-frames {--manifest=resources/hero-source/scenes.json} {--ffmpeg= : Local ffmpeg executable} {--preview : Build a contact sheet only}';

    protected $description = 'Build the reviewed chapter film, four-frame dissolves and budgeted WebP variants locally';

    private string $ffmpeg;

    public function handle(): int
    {
        $this->ffmpeg = $this->option('ffmpeg') ?: config('legion.ffmpeg', 'ffmpeg');
        $path = base_path($this->option('manifest'));
        $manifest = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $fps = (int) ($manifest['fps'] ?? 10);
        $overlap = (int) ($manifest['overlap'] ?? 4);
        if ($fps < 8 || $fps > 10 || $overlap !== 4 || empty($manifest['scenes'])) {
            throw new RuntimeException('Expected 8-10 fps, four overlap frames and at least one scene.');
        }
        $sources = [];
        foreach ($manifest['scenes'] as $scene) {
            $source = realpath(dirname($path).'/'.$scene['file']);
            $root = realpath(resource_path('hero-source')).DIRECTORY_SEPARATOR;
            if (! $source || ! str_starts_with($source, $root) || ! is_numeric($scene['start']) || ! is_numeric($scene['end']) || $scene['start'] < 0 || ($scene['end'] - $scene['start']) * $fps <= $overlap || empty($scene['caption'])) {
                throw new RuntimeException('Invalid source, interval or caption in scenes.json.');
            }
            $sources[] = $source;
        }
        $work = storage_path('app/hero-build-'.bin2hex(random_bytes(6)));
        File::ensureDirectoryExists($work);
        try {
            $chapters = [];
            $count = 0;
            $previews = [];
            File::ensureDirectoryExists($work.'/sequence');
            foreach ($manifest['scenes'] as $number => $scene) {
                $directory = $work.'/scene-'.$number;
                File::ensureDirectoryExists($directory);
                $duration = $scene['end'] - $scene['start'];
                $filters = [];
                foreach ($scene['masks'] ?? [] as [$x, $y, $w, $h]) {
                    $filters[] = "delogo=x={$x}:y={$y}:w={$w}:h={$h}";
                }
                if (isset($scene['crop'])) {
                    $filters[] = 'crop='.implode(':', $scene['crop']);
                }
                if ($scene['daylight'] ?? false) {
                    $filters[] = 'eq=brightness=-0.035:saturation=0.92,colorbalance=bs=0.015:rs=-0.01';
                }
                $filters[] = 'scale=720:1280:flags=lanczos,setsar=1';
                if ($this->option('preview')) {
                    $this->runFfmpeg(['-ss', (string) $scene['start'], '-i', $sources[$number], '-an', '-vf', implode(',', $filters), '-ss', (string) ($duration / 2), '-frames:v', '1', $directory.'/0000.png']);
                    $previews[] = $directory.'/0000.png';

                    continue;
                }
                if (isset($scene['fade_out'])) {
                    $start = max(0, $duration - 1 / $fps - $scene['fade_out']);
                    $filters[] = "fade=t=out:st={$start}:d={$scene['fade_out']}";
                }
                $filters[] = "fps={$fps}";
                $this->runFfmpeg(['-ss', (string) $scene['start'], '-t', (string) $duration, '-i', $sources[$number], '-an', '-vf', implode(',', $filters), '-frames:v', (string) (int) floor($duration * $fps), '-start_number', '0', $directory.'/%04d.png']);
                $frames = glob($directory.'/*.png');
                sort($frames);
                if (count($frames) <= $overlap) {
                    throw new RuntimeException('Scene too short after decoding.');
                }
                $start = $number ? $count - $overlap : 0;
                $chapters[] = ['caption' => $scene['caption'], 'start' => $number ? $start + intdiv($overlap, 2) : 0, 'transitionStart' => $start];
                foreach ($frames as $index => $frame) {
                    $target = $work.'/sequence/'.sprintf('%04d.png', $start + $index);
                    if ($number && $index < $overlap) {
                        $previous = imagecreatefrompng($target);
                        $incoming = imagecreatefrompng($frame);
                        imagecopymerge($previous, $incoming, 0, 0, 0, 0, 720, 1280, (int) round(100 * ($index + 1) / ($overlap + 1)));
                        imagepng($previous, $target);
                        imagedestroy($previous);
                        imagedestroy($incoming);
                    } else {
                        copy($frame, $target);
                    }
                }
                $count = $start + count($frames);
                $this->line('Scene '.($number + 1).': '.count($frames).' frames');
            }
            if ($this->option('preview')) {
                $sheet = imagecreatetruecolor(count($previews) * 180, 356);
                $ink = imagecolorallocate($sheet, 240, 240, 232);
                foreach ($previews as $number => $path) {
                    $frame = imagecreatefrompng($path);
                    imagecopyresampled($sheet, $frame, $number * 180, 0, 0, 0, 180, 320, 720, 1280);
                    $scene = $manifest['scenes'][$number];
                    imagestring($sheet, 3, $number * 180 + 6, 329, ($number + 1).': '.$scene['start'].' - '.$scene['end'].'s', $ink);
                    imagedestroy($frame);
                }
                File::ensureDirectoryExists(base_path('reports/hero-film'));
                imagejpeg($sheet, base_path('reports/hero-film/contact-sheet.jpg'), 90);
                imagedestroy($sheet);
                $this->info('reports/hero-film/contact-sheet.jpg');

                return self::SUCCESS;
            }
            $result = ['version' => 1, 'fps' => $fps, 'overlap' => $overlap, 'count' => $count, 'chapters' => $chapters];
            foreach ($result['chapters'] as $i => &$chapter) {
                $chapter['end'] = ($chapters[$i + 1]['start'] ?? $count) - 1;
            }
            unset($chapter);
            $revision = substr(hash('sha256', file_get_contents(base_path($this->option('manifest'))).implode('', array_map(fn (string $file): string => hash_file('sha256', $file), $sources)).hash_file('sha256', __FILE__)), 0, 12);
            $url = '/static/hero-film/'.$revision;
            $destination = public_path(ltrim($url, '/'));
            foreach (['desktop' => [720, 1280, 5000000], 'mobile' => [540, 960, 3000000]] as $name => [$width, $height, $budget]) {
                File::ensureDirectoryExists($work.'/'.$name);
                for ($quality = $name === 'desktop' ? 85 : 75; $quality >= 45; $quality -= 5) {
                    $this->runFfmpeg(['-framerate', (string) $fps, '-i', $work.'/sequence/%04d.png', '-an', '-vf', "scale={$width}:{$height}:flags=lanczos", '-c:v', 'libwebp', '-quality', (string) $quality, '-compression_level', '6', '-start_number', '0', $work.'/'.$name.'/%04d.webp']);
                    $bytes = array_sum(array_map('filesize', glob($work.'/'.$name.'/*.webp')));
                    if ($bytes <= $budget) {
                        break;
                    }
                }
                if ($bytes > $budget) {
                    throw new RuntimeException($name.' exceeds budget. Shorten scenes.');
                }
                $result[$name] = ['width' => $width, 'height' => $height, 'bytes' => $bytes, 'quality' => $quality, 'base' => $url.'/'.$name.'/'];
                $this->info("{$name}: {$bytes} bytes, quality {$quality}");
            }
            $poster = imagecreatefrompng($work.'/sequence/0000.png');
            for ($quality = 85; $quality >= 45; $quality -= 5) {
                imagewebp($poster, $work.'/poster.webp', $quality);
                clearstatcache(true, $work.'/poster.webp');
                if (filesize($work.'/poster.webp') <= 60000) {
                    break;
                }
            }
            imagedestroy($poster);
            if (filesize($work.'/poster.webp') > 60000) {
                throw new RuntimeException('Poster exceeds 60 KB.');
            }
            $result['poster'] = $url.'/poster.webp';
            File::ensureDirectoryExists($destination);
            foreach (['desktop', 'mobile'] as $name) {
                File::copyDirectory($work.'/'.$name, $destination.'/'.$name);
            }
            copy($work.'/poster.webp', $destination.'/poster.webp');
            file_put_contents(public_path('static/hero-film/manifest.json'), json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
            $this->info("Published {$count} frames per variant. No production ffmpeg required.");
        } finally {
            File::deleteDirectory($work);
        }

        return self::SUCCESS;
    }

    private function runFfmpeg(array $arguments): void
    {
        (new Process([$this->ffmpeg, '-hide_banner', '-loglevel', 'error', '-y', ...$arguments]))->setTimeout(600)->mustRun();
    }
}
