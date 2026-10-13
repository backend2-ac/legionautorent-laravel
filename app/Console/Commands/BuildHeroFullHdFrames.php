<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class BuildHeroFullHdFrames extends Command
{
    protected $signature = 'hero:build-fullhd {--quality=86 : WebP quality from 1 to 100} {--ffmpeg= : Path to ffmpeg}';

    protected $description = 'Build native Full HD hero packs from the approved master without resizing or changing timing';

    public function handle(): int
    {
        $quality = (int) $this->option('quality');
        if ($quality < 1 || $quality > 100) {
            $this->error('WebP quality must be between 1 and 100.');

            return self::FAILURE;
        }
        $manifestPath = public_path('static/hero/sequence.json');
        $manifest = json_decode(file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $master = public_path(ltrim($manifest['master'], '/'));
        if (hash_file('sha256', $master) !== $manifest['master_sha256']) {
            throw new \RuntimeException('The approved master checksum does not match.');
        }
        $directory = storage_path('app/hero-fullhd-'.bin2hex(random_bytes(6)));
        mkdir($directory);
        try {
            $process = new Process([
                $this->option('ffmpeg') ?: config('legion.ffmpeg'), '-hide_banner', '-loglevel', 'error', '-y',
                '-i', $master, '-map', '0:v:0', '-an', '-vf', 'setsar=1', '-fps_mode', 'passthrough',
                '-c:v', 'libwebp', '-quality', (string) $quality, '-compression_level', '5',
                '-start_number', '0', $directory.'/%04d.webp',
            ]);
            $process->setTimeout(600)->mustRun();
            $frames = glob($directory.'/*.webp');
            sort($frames);
            if (count($frames) !== $manifest['count']) {
                throw new \RuntimeException('Full HD must preserve every frame of the orbit.');
            }
            $variant = ['width' => $manifest['source_width'], 'height' => $manifest['source_height'], 'count' => count($frames), 'quality' => $quality, 'packs' => [], 'bytes' => 0];
            $packDirectory = dirname($manifest['desktop']['packs'][0]['url']);
            foreach (array_chunk($frames, $manifest['pack_size']) as $number => $chunk) {
                $payload = '';
                foreach ($chunk as $frame) {
                    $info = getimagesize($frame);
                    if ($info[0] !== $variant['width'] || $info[1] !== $variant['height']) {
                        throw new \RuntimeException('Unexpected Full HD frame dimensions.');
                    }
                    $bytes = file_get_contents($frame);
                    $payload .= pack('V', strlen($bytes)).$bytes;
                }
                $hash = hash('sha256', $payload);
                $url = $packDirectory.'/fullhd-q'.$quality.'-'.str_pad((string) $number, 2, '0', STR_PAD_LEFT).'-'.substr($hash, 0, 12).'.pack';
                file_put_contents(public_path(ltrim($url, '/')), $payload);
                $variant['packs'][] = ['url' => $url, 'start' => $number * $manifest['pack_size'], 'count' => count($chunk), 'bytes' => strlen($payload), 'sha256' => $hash];
                $variant['bytes'] += strlen($payload);
            }
            $posterUrl = '/static/hero/hero-poster-fullhd-'.substr(hash_file('sha256', $frames[0]), 0, 12).'.webp';
            copy($frames[0], public_path(ltrim($posterUrl, '/')));
            $poster = imagecreatefromwebp($frames[0]);
            $jpegUrl = str_replace('.webp', '.jpg', $posterUrl);
            imagejpeg($poster, public_path(ltrim($jpegUrl, '/')), 92);
            imagedestroy($poster);
            $variant['poster'] = $posterUrl;
            $variant['poster_bytes'] = filesize($frames[0]);
            $variant['poster_jpeg'] = $jpegUrl;
            $manifest['fullhd'] = $variant;
            $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
            $json = preg_replace_callback('/^( +)/m', fn (array $match): string => str_repeat(' ', (int) (strlen($match[1]) / 2)), $json);
            file_put_contents($manifestPath, $json."\n");
            $this->info('Full HD: '.$variant['count'].' frames, '.$variant['width'].'×'.$variant['height'].', '.$variant['bytes'].' bytes.');
        } finally {
            foreach (glob($directory.'/*.webp') as $frame) {
                unlink($frame);
            }
            rmdir($directory);
        }

        return self::SUCCESS;
    }
}
