<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class BuildHeroJpegFrames extends Command
{
    protected $signature = 'hero:build-jpeg {--quality=80 : JPEG quality from 1 to 100}';

    protected $description = 'Build a JPEG frame fallback for browsers without WebP from the approved mobile sequence';

    public function handle(): int
    {
        $quality = (int) $this->option('quality');
        if ($quality < 1 || $quality > 100) {
            $this->error('JPEG quality must be between 1 and 100.');

            return self::FAILURE;
        }
        $path = public_path('static/hero/sequence.json');
        $manifest = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $fallback = ['format' => 'image/jpeg', 'width' => $manifest['mobile']['width'], 'height' => $manifest['mobile']['height'], 'quality' => $quality, 'count' => 0, 'bytes' => 0, 'packs' => []];
        foreach ($manifest['mobile']['packs'] as $number => $source) {
            $input = file_get_contents(public_path(ltrim($source['url'], '/')));
            if (hash('sha256', $input) !== $source['sha256']) {
                throw new \RuntimeException('The source frame pack checksum does not match.');
            }
            $offset = 0;
            $output = '';
            $count = 0;
            while ($offset < strlen($input)) {
                if ($offset + 4 > strlen($input)) {
                    throw new \RuntimeException('Incomplete frame pack header.');
                }
                $size = unpack('Vsize', substr($input, $offset, 4))['size'];
                $offset += 4;
                if ($size < 1 || $offset + $size > strlen($input)) {
                    throw new \RuntimeException('Invalid frame pack length.');
                }
                $image = imagecreatefromstring(substr($input, $offset, $size));
                $offset += $size;
                if ($image === false) {
                    throw new \RuntimeException('The source WebP frame could not be decoded.');
                }
                ob_start();
                imagejpeg($image, null, $quality);
                $jpeg = ob_get_clean();
                imagedestroy($image);
                $output .= pack('V', strlen($jpeg)).$jpeg;
                $count++;
            }
            if ($count !== $source['count']) {
                throw new \RuntimeException('Frame count mismatch.');
            }
            $hash = hash('sha256', $output);
            $url = dirname($source['url']).'/jpeg-q'.$quality.'-'.str_pad((string) $number, 2, '0', STR_PAD_LEFT).'-'.substr($hash, 0, 12).'.pack';
            file_put_contents(public_path(ltrim($url, '/')), $output);
            $fallback['packs'][] = ['url' => $url, 'start' => $fallback['count'], 'count' => $count, 'bytes' => strlen($output), 'sha256' => $hash];
            $fallback['count'] += $count;
            $fallback['bytes'] += strlen($output);
        }
        if ($fallback['count'] !== $manifest['count']) {
            throw new \RuntimeException('The full orbit must have the original frame count.');
        }
        foreach (['desktop', 'mobile'] as $size) {
            $image = imagecreatefromwebp(public_path('static/hero/hero-poster-60fps-'.$size.'.webp'));
            imagejpeg($image, public_path('static/hero/hero-poster-60fps-'.$size.'.jpg'), 90);
            imagedestroy($image);
        }
        $oldUrls = array_column($manifest['jpeg']['packs'] ?? [], 'url');
        $manifest['jpeg'] = $fallback;
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        $json = preg_replace_callback('/^( +)/m', fn (array $match): string => str_repeat(' ', (int) (strlen($match[1]) / 2)), $json);
        file_put_contents($path, $json."\n");
        $currentUrls = array_column($fallback['packs'], 'url');
        foreach (array_diff($oldUrls, $currentUrls) as $oldUrl) {
            if (preg_match('#^/static/hero/frames-[a-f0-9]+/jpeg-q[0-9]+-[0-9]+-[a-f0-9]+\.pack$#', $oldUrl)) {
                unlink(public_path(ltrim($oldUrl, '/')));
            }
        }
        $this->info('JPEG fallback: '.$fallback['count'].' frames, '.$fallback['bytes'].' bytes.');

        return self::SUCCESS;
    }
}
