<?php

namespace App\Services\Pdf;

use setasign\Fpdi\Fpdi;

class WatermarkPdf extends Fpdi
{
    /** @var array<int, array{ca: float, CA: float, BM: string, n?: int}> */
    private array $extGStates = [];

    public function SetAlpha(float $alpha, string $blendMode = 'Normal'): void
    {
        $gs = $this->addExtGState(['ca' => $alpha, 'CA' => $alpha, 'BM' => '/' . $blendMode]);
        $this->_out(sprintf('/GS%d gs', $gs));
    }

    private function addExtGState(array $params): int
    {
        $n = count($this->extGStates) + 1;
        $this->extGStates[$n] = $params;
        return $n;
    }

    public function StartTransform(): void
    {
        $this->_out('q');
    }

    public function StopTransform(): void
    {
        $this->_out('Q');
    }

    public function Rotate(float $angle, ?float $x = null, ?float $y = null): void
    {
        $x ??= $this->x;
        $y ??= $this->y;

        $rad = deg2rad($angle);
        $c = cos($rad);
        $s = sin($rad);
        $cx = $x * $this->k;
        $cy = ($this->h - $y) * $this->k;

        $this->_out(sprintf(
            '%.5F %.5F %.5F %.5F %.2F %.2F cm 1 0 0 1 %.2F %.2F cm',
            $c, $s, -$s, $c, $cx, $cy, -$cx, -$cy
        ));
    }

    protected function _enddoc()
    {
        if (! empty($this->extGStates) && $this->PDFVersion < '1.4') {
            $this->PDFVersion = '1.4';
        }
        parent::_enddoc();
    }

    protected function _putextgstates(): void
    {
        foreach ($this->extGStates as $i => $params) {
            $this->_newobj();
            $this->extGStates[$i]['n'] = $this->n;
            $this->_put('<</Type /ExtGState');
            $this->_put(sprintf('/ca %.3F', $params['ca']));
            $this->_put(sprintf('/CA %.3F', $params['CA']));
            $this->_put('/BM ' . $params['BM']);
            $this->_put('>>');
            $this->_put('endobj');
        }
    }

    protected function _putresourcedict(): void
    {
        parent::_putresourcedict();
        $this->_put('/ExtGState <<');
        foreach ($this->extGStates as $k => $v) {
            if (isset($v['n'])) {
                $this->_put('/GS' . $k . ' ' . $v['n'] . ' 0 R');
            }
        }
        $this->_put('>>');
    }

    protected function _putresources(): void
    {
        $this->_putextgstates();
        parent::_putresources();
    }
}