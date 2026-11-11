<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class BuildHeroSequence extends Command
{
    protected $signature = 'hero:build-sequence
        {landscape : Approved 16:9 master video}
        {--portrait= : Optional approved square or vertical master for portrait screens}
        {--frames=144 : Number of frames every variant must contain}
        {--fps=24 : Playback rate written to the manifest}
        {--ffmpeg= : Path to ffmpeg}';

    protected $description = 'Build every hero frame-pack variant, posters and sequence.json from approved masters into public/media';

    private const PACK_SIZE = 8;

    /**
     * Frame-pack variants cut from the landscape master: [name, width, height, format, quality].
     *
     * @var list<array{0: string, 1: int, 2: int, 3: string, 4: int}>
     */
    private const LANDSCAPE_VARIANTS = [
        ['mobile', 768, 432, 'webp', 74],
        ['desktop', 1280, 720, 'webp', 78],
        ['fullhd', 1920, 1080, 'webp', 80],
        ['jpeg', 768, 432, 'jpeg', 78],
    ];

    public function handle(): int
    {
        $landscape = $this->argument('landscape');
        $portrait = $this->option('portrait');
        $count = (int) $this->option('frames');
        foreach (array_filter([$landscape, $portrait]) as $source) {
            if (! is_file($source)) {
                $this->error('Master not found: '.$source);

                return self::FAILURE;
            }
        }
        if ($count < 24) {
            $this->error('A hero sequence needs at least 24 frames.');

            return self::FAILURE;
        }

        $hash = hash_file('sha256', $landscape);
        $folder = 'hero-'.substr($hash, 0, 10);
        $directory = public_path('media/'.$folder);
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        copy($landscape, $directory.'/master.mp4');
        $manifest = [
            'version' => 1,
            'master' => '/media/'.$folder.'/master.mp4',
            'master_sha256' => $hash,
            'count' => $count,
            'fps' => (int) $this->option('fps'),
            'duration' => round($count / (int) $this->option('fps'), 2),
            'source_width' => 1920,
            'source_height' => 1080,
            'pack_size' => self::PACK_SIZE,
            'audio' => false,
        ];

        foreach (self::LANDSCAPE_VARIANTS as [$name, $width, $height, $format, $quality]) {
            $frames = $this->frames($landscape, $width, $height, $format, $quality, $count);
            $manifest[$name] = $this->packVariant($frames, $folder, $name, $width, $height);
            $this->cleanup($frames);
        }

        $previewFrames = $this->frames($landscape, 640, 360, 'webp', 65, $count);
        $manifest['preview'] = [
            'webp' => $this->packPreview($previewFrames, $folder, 'preview-webp', 640, 360, 'webp'),
            'jpeg' => $this->packPreview($previewFrames, $folder, 'preview-jpeg', 640, 360, 'jpeg'),
        ];
        $this->cleanup($previewFrames);

        $posters = $this->frames($landscape, 1920, 1080, 'webp', 82, $count);
        $manifest['fullhd']['poster'] = $this->savePoster($posters[0], $folder, 'poster');
        $manifest['ending'] = $this->savePoster($posters[$count - 1], $folder, 'ending');
        $this->cleanup($posters);

        if ($portrait) {
            copy($portrait, $directory.'/master-portrait.mp4');
            [$width, $height] = $this->dimensions($portrait, 810);
            $frames = $this->frames($portrait, $width, $height, 'webp', 76, $count);
            $manifest['portrait'] = $this->packVariant($frames, $folder, 'portrait', $width, $height);
            $manifest['portrait']['poster'] = $this->savePoster($frames[0], $folder, 'poster-portrait');
            $this->cleanup($frames);

            [$previewWidth, $previewHeight] = $this->dimensions($portrait, 360);
            $previewFrames = $this->frames($portrait, $previewWidth, $previewHeight, 'webp', 65, $count);
            $manifest['preview']['portrait'] = $this->packPreview($previewFrames, $folder, 'preview-portrait', $previewWidth, $previewHeight, 'webp');
            $this->cleanup($previewFrames);
        }

        file_put_contents($directory.'/sequence.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        foreach (['mobile', 'desktop', 'fullhd', 'jpeg', 'portrait'] as $name) {
            if (isset($manifest[$name])) {
                $this->info(sprintf('%s: %d×%d, %.1f MB', $name, $manifest[$name]['width'], $manifest[$name]['height'], $manifest[$name]['bytes'] / 1048576));
            }
        }
        $this->info('Hero video path: '.$manifest['master']);
        $this->info('Desktop poster: '.$manifest['fullhd']['poster']);
        if (isset($manifest['portrait'])) {
            $this->info('Mobile poster: '.$manifest['portrait']['poster']);
        }
        $this->info('Ending image: '.$manifest['ending']);

        return self::SUCCESS;
    }

    /**
     * Scale a master to the given box keeping its aspect ratio, with even sides.
     *
     * @return array{0: int, 1: int}
     */
    private function dimensions(string $source, int $longSide): array
    {
        $probe = new Process(['ffprobe', '-v', 'error', '-select_streams', 'v:0', '-show_entries', 'stream=width,height', '-of', 'csv=p=0', $source]);
        $probe->mustRun();
        [$width, $height] = array_map('intval', explode(',', trim($probe->getOutput())));
        $scale = $longSide / max($width, $height);

        return [(int) (round($width * $scale / 2) * 2), (int) (round($height * $scale / 2) * 2)];
    }

    /**
     * Decode every master frame at the target size and keep exactly $count evenly spaced frames.
     *
     * @return list<string>
     */
    private function frames(string $source, int $width, int $height, string $format, int $quality, int $count): array
    {
        $directory = storage_path('app/hero-build-'.bin2hex(random_bytes(6)));
        mkdir($directory);
        $extension = $format === 'jpeg' ? 'jpg' : 'webp';
        $codec = $format === 'jpeg'
            ? ['-c:v', 'mjpeg', '-q:v', (string) max(2, (int) round((100 - $quality) / 8))]
            : ['-c:v', 'libwebp', '-quality', (string) $quality, '-compression_level', '6'];
        $process = new Process([
            $this->option('ffmpeg') ?: config('legion.ffmpeg'), '-hide_banner', '-loglevel', 'error', '-y',
            '-i', $source, '-map', '0:v:0', '-an', '-vf', "scale={$width}:{$height}:flags=lanczos,setsar=1",
            '-fps_mode', 'passthrough', ...$codec, '-start_number', '0', $directory.'/%04d.'.$extension,
        ]);
        $process->setTimeout(900)->mustRun();
        $all = glob($directory.'/*.'.$extension);
        sort($all);
        if (count($all) < $count) {
            throw new \RuntimeException(sprintf('The master has %d frames; %d are required.', count($all), $count));
        }
        $keep = [];
        for ($index = 0; $index < $count; $index++) {
            $keep[] = $all[(int) round($index * (count($all) - 1) / ($count - 1))];
        }
        foreach (array_diff($all, $keep) as $unused) {
            unlink($unused);
        }

        return $keep;
    }

    /**
     * @param  list<string>  $frames
     * @return array{width: int, height: int, count: int, packs: list<array<string, mixed>>, bytes: int}
     */
    private function packVariant(array $frames, string $folder, string $name, int $width, int $height): array
    {
        $variant = ['width' => $width, 'height' => $height, 'count' => count($frames), 'packs' => [], 'bytes' => 0];
        foreach (array_chunk($frames, self::PACK_SIZE) as $number => $chunk) {
            $start = $number * self::PACK_SIZE;
            $variant['packs'][] = $this->writePack($chunk, $folder, $name.'-'.str_pad((string) $number, 2, '0', STR_PAD_LEFT), range($start, $start + count($chunk) - 1));
        }
        $variant['bytes'] = array_sum(array_column($variant['packs'], 'bytes'));

        return $variant;
    }

    /**
     * Four interleaved packs that each cover the whole move, so a slow connection still scrubs end to end.
     *
     * @param  list<string>  $frames
     * @return array{width: int, height: int, count: int, packs: list<array<string, mixed>>, bytes: int}
     */
    private function packPreview(array $frames, string $folder, string $name, int $width, int $height, string $format): array
    {
        $last = count($frames) - 1;
        $lanes = [];
        foreach ([0, 10, 5, 15] as $lane => $offset) {
            $lanes[$lane] = range($offset, $last, 20);
        }
        if (! in_array($last, $lanes[0], true)) {
            $lanes[0][] = $last;
        }
        $variant = ['width' => $width, 'height' => $height, 'count' => count(array_merge(...$lanes)), 'packs' => [], 'bytes' => 0];
        foreach ($lanes as $number => $indices) {
            $chunk = array_map(fn (int $index): string => $frames[$index], $indices);
            $variant['packs'][] = $this->writePack($chunk, $folder, $name.'-'.$number, $indices, $format === 'jpeg' ? 75 : null);
        }
        $variant['bytes'] = array_sum(array_column($variant['packs'], 'bytes'));

        return $variant;
    }

    /**
     * Concatenate frames as [uint32 little-endian length][bytes] records, the format the hero player unpacks.
     *
     * @param  list<string>  $frames
     * @param  list<int>  $indices
     * @return array{url: string, start: int, count: int, indices: list<int>, bytes: int, sha256: string}
     */
    private function writePack(array $frames, string $folder, string $name, array $indices, ?int $jpegQuality = null): array
    {
        $payload = '';
        foreach ($frames as $frame) {
            $bytes = file_get_contents($frame);
            if ($jpegQuality !== null) {
                $image = imagecreatefromwebp($frame);
                ob_start();
                imagejpeg($image, null, $jpegQuality);
                $bytes = ob_get_clean();
                imagedestroy($image);
            }
            $payload .= pack('V', strlen($bytes)).$bytes;
        }
        $hash = hash('sha256', $payload);
        $url = '/media/'.$folder.'/'.$name.'-'.substr($hash, 0, 12).'.pack';
        file_put_contents(public_path(ltrim($url, '/')), $payload);

        return ['url' => $url, 'start' => $indices[0], 'count' => count($frames), 'indices' => $indices, 'bytes' => strlen($payload), 'sha256' => $hash];
    }

    /**
     * Save a WebP poster and a same-named JPEG fallback next to the packs; returns the WebP URL.
     */
    private function savePoster(string $frame, string $folder, string $name): string
    {
        $webp = '/media/'.$folder.'/'.$name.'.webp';
        copy($frame, public_path(ltrim($webp, '/')));
        $image = imagecreatefromwebp($frame);
        $jpeg = '/media/'.$folder.'/'.$name.'.jpg';
        imagejpeg($image, public_path(ltrim($jpeg, '/')), 88);
        imagedestroy($image);

        return $webp;
    }

    /**
     * @param  list<string>  $frames
     */
    private function cleanup(array $frames): void
    {
        if ($frames === []) {
            return;
        }
        $directory = dirname($frames[0]);
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
}
