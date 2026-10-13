<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class BuildHeroPreviewFrames extends Command
{
    protected $signature = 'hero:build-preview {--ffmpeg= : Path to ffmpeg}';

    protected $description = 'Build a small, progressively sampled whole-orbit fallback for slow connections';

    public function handle(): int
    {
        $manifestPath = public_path('static/hero/sequence.json');
        $manifest = json_decode(file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $master = public_path(ltrim($manifest['master'], '/'));
        if (hash_file('sha256', $master) !== $manifest['master_sha256']) {
            throw new \RuntimeException('The approved master checksum does not match.');
        }
        $last = $manifest['count'] - 1;
        $indices = array_values(array_unique(array_merge(range(0, $last, 5), [$last])));
        $directory = storage_path('app/hero-preview-'.bin2hex(random_bytes(6)));
        mkdir($directory);
        try {
            $process = new Process([
                $this->option('ffmpeg') ?: config('legion.ffmpeg'), '-hide_banner', '-loglevel', 'error', '-y',
                '-i', $master, '-map', '0:v:0', '-an', '-vf', "select='not(mod(n,5))+eq(n,{$last})',scale=640:-2:flags=lanczos,setsar=1",
                '-fps_mode', 'vfr', '-c:v', 'libwebp', '-quality', '65', '-compression_level', '6',
                '-start_number', '0', $directory.'/%04d.webp',
            ]);
            $process->setTimeout(600)->mustRun();
            $frames = glob($directory.'/*.webp');
            sort($frames);
            if (count($frames) !== count($indices)) {
                throw new \RuntimeException('Unexpected preview frame count.');
            }
            $lanes = [[], [], [], []];
            foreach ($indices as $position => $index) {
                $lane = $position === count($indices) - 1 ? 0 : [0, 2, 1, 3][$position % 4];
                $lanes[$lane][] = $position;
            }
            foreach (['webp', 'jpeg'] as $format) {
                $variant = ['width' => 640, 'height' => 360, 'count' => count($indices), 'packs' => [], 'bytes' => 0];
                foreach ($lanes as $number => $positions) {
                    $payload = '';
                    foreach ($positions as $position) {
                        $bytes = file_get_contents($frames[$position]);
                        if ($format === 'jpeg') {
                            $image = imagecreatefromwebp($frames[$position]);
                            ob_start();
                            imagejpeg($image, null, 75);
                            $bytes = ob_get_clean();
                            imagedestroy($image);
                        }
                        $payload .= pack('V', strlen($bytes)).$bytes;
                    }
                    $hash = hash('sha256', $payload);
                    $url = dirname($manifest['desktop']['packs'][0]['url']).'/preview-'.$format.'-'.$number.'-'.substr($hash, 0, 12).'.pack';
                    file_put_contents(public_path(ltrim($url, '/')), $payload);
                    $variant['packs'][] = ['url' => $url, 'indices' => array_map(fn (int $position): int => $indices[$position], $positions), 'bytes' => strlen($payload), 'sha256' => $hash];
                    $variant['bytes'] += strlen($payload);
                }
                $manifest['preview'][$format] = $variant;
                $this->info($format.': '.$variant['count'].' preview frames, '.$variant['bytes'].' bytes.');
            }
            $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
            $json = preg_replace_callback('/^( +)/m', fn (array $match): string => str_repeat(' ', (int) (strlen($match[1]) / 2)), $json);
            file_put_contents($manifestPath, $json."\n");
        } finally {
            foreach (glob($directory.'/*.webp') as $frame) {
                unlink($frame);
            }
            rmdir($directory);
        }

        return self::SUCCESS;
    }
}
