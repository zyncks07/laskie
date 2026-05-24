<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for compressImage() in config/functions.php.
 *
 * Why these matter: receipts (mostly phone screenshots, 1–4 MB) flood the
 * uploads/ directory. compressImage() is what keeps the disk from filling on
 * a low-end host. Regressions here = silent storage bloat.
 */
final class ImageCompressionTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/laskie_img_' . uniqid();
        mkdir($this->tmpDir, 0775, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->tmpDir)) return;
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) @unlink($f);
        @rmdir($this->tmpDir);
    }

    // Build a "receipt-ish" image: large, mostly white background with text-like
    // dark rectangles. Synthetic noise compresses badly and would skew the test —
    // structured images compress like real receipts do.
    private function makeReceipt(string $path, int $w, int $h, string $format, int $quality = 95): void
    {
        $im = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($im, 255, 255, 255);
        $black = imagecolorallocate($im, 30, 30, 30);
        $gray  = imagecolorallocate($im, 180, 180, 180);
        imagefilledrectangle($im, 0, 0, $w, $h, $white);

        // Header band
        imagefilledrectangle($im, 0, 0, $w, 80, $gray);
        // "Lines of text" as thin black bars
        for ($y = 120; $y < $h - 120; $y += 40) {
            $lineW = (int)($w * (0.4 + (mt_rand(0, 50) / 100)));
            imagefilledrectangle($im, 60, $y, 60 + $lineW, $y + 18, $black);
        }
        // Footer band
        imagefilledrectangle($im, 0, $h - 80, $w, $h, $gray);

        if ($format === 'jpeg') {
            imagejpeg($im, $path, $quality);
        } elseif ($format === 'png') {
            imagepng($im, $path, 0); // no compression → big file
        } elseif ($format === 'webp') {
            imagewebp($im, $path, $quality);
        }
        imagedestroy($im);
    }

    #[Test]
    public function compresses_large_jpeg_and_keeps_it_readable(): void
    {
        $src = $this->tmpDir . '/receipt.jpg';
        $this->makeReceipt($src, 2400, 3000, 'jpeg', 95);
        $origSize = filesize($src);

        $r = compressImage($src);

        $this->assertTrue($r['compressed'], 'large JPEG should compress');
        $this->assertLessThan($origSize, $r['new_size']);
        $this->assertSame($src, $r['new_path'], 'extension stays .jpg');
        $this->assertNotFalse(@getimagesize($src), 'result must still be a valid image');

        // Resized to the long-edge cap
        [$nw, $nh] = getimagesize($src);
        $this->assertLessThanOrEqual(IMAGE_COMPRESSION_MAX_DIM, max($nw, $nh));
    }

    #[Test]
    public function converts_opaque_png_screenshot_to_jpeg(): void
    {
        $src = $this->tmpDir . '/screenshot.png';
        $this->makeReceipt($src, 1500, 2400, 'png');
        $origSize = filesize($src);

        $r = compressImage($src);

        $this->assertTrue($r['compressed']);
        $this->assertNotNull($r['new_path']);
        $this->assertStringEndsWith('.jpg', $r['new_path'], 'opaque PNG should become JPEG');
        $this->assertFileExists($r['new_path']);
        $this->assertFileDoesNotExist($src, 'original PNG should be removed after format swap');
        $this->assertLessThan($origSize, $r['new_size']);
        // Phone-screenshot use case: expect at least 50% saved on a flat synthetic image
        $this->assertLessThan($origSize * 0.5, $r['new_size']);
    }

    #[Test]
    public function preserves_transparent_png_as_png(): void
    {
        $src = $this->tmpDir . '/logo.png';
        $w = 800; $h = 600;
        $im = imagecreatetruecolor($w, $h);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        $transparent = imagecolorallocatealpha($im, 0, 0, 0, 127);
        imagefilledrectangle($im, 0, 0, $w, $h, $transparent);
        $red = imagecolorallocate($im, 200, 50, 50);
        imagefilledrectangle($im, 100, 100, 700, 500, $red);
        imagepng($im, $src, 0);
        imagedestroy($im);

        $r = compressImage($src, ['png_to_jpeg' => false]);

        // May or may not compress depending on synthetic content; assert behavior is sane.
        if ($r['compressed']) {
            $this->assertSame($src, $r['new_path'], 'PNG with alpha must stay .png when png_to_jpeg=false');
        }
        $this->assertFileExists($src);
        $info = getimagesize($src);
        $this->assertSame('image/png', $info['mime']);
    }

    #[Test]
    public function leaves_non_image_files_untouched(): void
    {
        $src = $this->tmpDir . '/contract.pdf';
        file_put_contents($src, "%PDF-1.4\n%fake pdf body\n%%EOF");
        $origSize = filesize($src);
        $origHash = md5_file($src);

        $r = compressImage($src);

        $this->assertFalse($r['compressed']);
        $this->assertSame('not-image', $r['reason']);
        $this->assertSame($origSize, filesize($src));
        $this->assertSame($origHash, md5_file($src), 'PDF bytes must be byte-identical');
    }

    #[Test]
    public function skips_gif_to_avoid_breaking_animations(): void
    {
        $src = $this->tmpDir . '/anim.gif';
        $im = imagecreatetruecolor(100, 100);
        imagegif($im, $src);
        imagedestroy($im);
        $origHash = md5_file($src);

        $r = compressImage($src);

        $this->assertFalse($r['compressed']);
        $this->assertSame('gif-skipped', $r['reason']);
        $this->assertSame($origHash, md5_file($src));
    }

    #[Test]
    public function bails_out_when_compression_would_not_save_space(): void
    {
        // Tiny image — re-encoding usually breaks even or grows.
        $src = $this->tmpDir . '/tiny.jpg';
        $im = imagecreatetruecolor(10, 10);
        imagejpeg($im, $src, 50);
        imagedestroy($im);
        $origHash = md5_file($src);

        $r = compressImage($src);

        // Either compressed (smaller) or skipped with 'no-gain' — both fine.
        if (!$r['compressed']) {
            $this->assertSame('no-gain', $r['reason']);
            $this->assertSame($origHash, md5_file($src), 'original must be preserved on no-gain');
        }
    }

    #[Test]
    public function returns_file_missing_for_nonexistent_path(): void
    {
        $r = compressImage($this->tmpDir . '/does-not-exist.jpg');
        $this->assertFalse($r['compressed']);
        $this->assertSame('file-missing', $r['reason']);
    }
}
