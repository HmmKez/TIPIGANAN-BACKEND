<?php

namespace Tests\Feature;

use App\Services\Pdf\WatermarkPdf;
use App\Support\PdfNormalizer;
use Symfony\Component\Process\Process;
use Tests\Support\MakesPdfs;
use Tests\TestCase;

// PdfNormalizer rewrites uploaded PDFs into a structure the free FPDI tier can
// parse. It runs on every upload, in front of the fixity hash, so the two ways
// it can go wrong are both severe: silently not rewriting (the thesis then
// won't open in the viewer at all), or losing the file outright.
class PdfNormalizerTest extends TestCase
{
    use MakesPdfs;

    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = storage_path('framework/testing/pdfnorm');

        if (! is_dir($this->workDir)) {
            mkdir($this->workDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_it_leaves_the_file_untouched_when_qpdf_is_not_installed(): void
    {
        // The whole point of the graceful-degradation rule: a machine without
        // qpdf must still be able to accept uploads. The file has to come out
        // byte-identical — a half-written file would be worse than no rewrite.
        config(['thesis.qpdf_binary' => 'qpdf-definitely-not-installed']);

        $path = $this->workDir . '/untouched.pdf';
        file_put_contents($path, $this->minimalPdf());
        $before = md5_file($path);

        $this->assertFalse(PdfNormalizer::normalize($path), 'normalize() must report failure, not success.');
        $this->assertSame($before, md5_file($path), 'The original file must be left exactly as it was.');
        $this->assertFileDoesNotExist($path . '.normalized.tmp');
        $this->assertFileDoesNotExist($path . '.orig.bak');
    }

    public function test_it_makes_an_object_stream_pdf_readable_by_fpdi(): void
    {
        $qpdf = $this->qpdfPath();

        if (! $qpdf) {
            $this->markTestSkipped('qpdf is not installed on this machine.');
        }

        config(['thesis.qpdf_binary' => $qpdf]);

        // Build the exact shape that breaks FPDI — object streams plus a
        // cross-reference stream, which is what Word and most scanners emit.
        $source = $this->workDir . '/source.pdf';
        $target = $this->workDir . '/objstm.pdf';
        file_put_contents($source, $this->minimalPdf());

        $build = new Process([$qpdf, '--object-streams=generate', $source, $target]);
        $build->run();

        $this->assertTrue(is_file($target), 'Could not build the object-stream fixture.');
        $this->assertStringContainsString('/ObjStm', file_get_contents($target));

        // Precondition: FPDI genuinely cannot read it. If this ever stops being
        // true the rest of the test proves nothing, so assert it rather than
        // assume it.
        $readable = true;
        try {
            (new WatermarkPdf())->setSourceFile($target);
        } catch (\Throwable $e) {
            $readable = false;
        }
        $this->assertFalse($readable, 'Fixture was already FPDI-readable; it cannot demonstrate the fix.');

        $this->assertTrue(PdfNormalizer::normalize($target));

        // Now it must open, and the temp/backup files must not be left behind.
        $pages = (new WatermarkPdf())->setSourceFile($target);
        $this->assertSame(1, $pages);
        $this->assertFileDoesNotExist($target . '.normalized.tmp');
        $this->assertFileDoesNotExist($target . '.orig.bak');
    }

    public function test_it_makes_an_encrypted_pdf_readable_when_it_opens_without_a_password(): void
    {
        $qpdf = $this->qpdfPath();

        if (! $qpdf) {
            $this->markTestSkipped('qpdf is not installed on this machine.');
        }

        config(['thesis.qpdf_binary' => $qpdf]);

        // Owner password, no user password: the shape Acrobat's "restrict
        // editing" and most journal downloads produce. Opens fine in any
        // reader, which is exactly why nobody suspects it — but FPDI refuses
        // it and the thesis never displays.
        $source = $this->workDir . '/enc-source.pdf';
        $target = $this->workDir . '/encrypted.pdf';
        file_put_contents($source, $this->minimalPdf(50));

        (new Process([
            $qpdf, '--encrypt', '--user-password=', '--owner-password=owner',
            '--bits=256', '--', $source, $target,
        ]))->run();

        $this->assertFileExists($target, 'Could not build the encrypted fixture.');

        $readable = true;
        try {
            (new WatermarkPdf())->setSourceFile($target);
        } catch (\Throwable $e) {
            $readable = false;
        }
        $this->assertFalse($readable, 'Fixture was not actually rejected by FPDI.');

        $this->assertTrue(PdfNormalizer::normalize($target));
        $this->assertSame(1, (new WatermarkPdf())->setSourceFile($target));
    }

    public function test_it_refuses_a_pdf_that_needs_a_password_and_leaves_it_intact(): void
    {
        $qpdf = $this->qpdfPath();

        if (! $qpdf) {
            $this->markTestSkipped('qpdf is not installed on this machine.');
        }

        config(['thesis.qpdf_binary' => $qpdf]);

        // The line that must not be crossed: --decrypt exists to repair files
        // the uploader can already open, not to strip protection from a
        // document that genuinely requires a password. qpdf cannot open this
        // one at all, so normalize() must fail and leave the bytes untouched.
        $source = $this->workDir . '/pw-source.pdf';
        $target = $this->workDir . '/password-protected.pdf';
        file_put_contents($source, $this->minimalPdf(50));

        (new Process([
            $qpdf, '--encrypt', '--user-password=secret', '--owner-password=owner',
            '--bits=256', '--', $source, $target,
        ]))->run();

        $this->assertFileExists($target, 'Could not build the password-protected fixture.');
        $before = md5_file($target);

        $this->assertFalse(PdfNormalizer::normalize($target), 'A password-protected PDF must not report success.');
        $this->assertSame($before, md5_file($target), 'The file must be left exactly as uploaded.');
        $this->assertFileDoesNotExist($target . '.normalized.tmp');
        $this->assertFileDoesNotExist($target . '.orig.bak');
    }

    public function test_it_does_not_inflate_the_file_the_way_qdf_mode_would(): void
    {
        $qpdf = $this->qpdfPath();

        if (! $qpdf) {
            $this->markTestSkipped('qpdf is not installed on this machine.');
        }

        config(['thesis.qpdf_binary' => $qpdf]);

        // Pins the --qdf decision. servePdf() stamps and streams the file on
        // every view, so an uncompressed rewrite is paid on every read, not
        // once on disk — measured at 16x on a real thesis.
        $source = $this->workDir . '/size-source.pdf';
        $target = $this->workDir . '/size-target.pdf';
        file_put_contents($source, $this->minimalPdf(4000));

        // --compress-streams=y is what a real-world PDF already arrives with;
        // the fixture has to match that for the comparison to mean anything.
        (new Process([
            $qpdf, '--object-streams=generate', '--compress-streams=y', $source, $target,
        ]))->run();

        $before = filesize($target);

        $this->assertTrue(PdfNormalizer::normalize($target));

        $this->assertLessThan(
            $before * 3,
            filesize($target),
            'Normalized output ballooned — has --qdf been added back to QPDF_ARGS?'
        );
    }
}
