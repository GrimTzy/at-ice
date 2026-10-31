<?php

namespace App\Support\ThesisDemo;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// Small, clearly labeled sample files (no GD needed): a one-page PDF with text
// lines, and a PNG with large block letters. Every file says it is a
// fictional sample, so nobody mistakes it for a real document or photo.
class SampleFiles
{
    public const BANNER = 'SAMPLE ONLY - FICTIONAL THESIS DATA';

    // $dry: preflight; nothing is written to disk.
    public function __construct(private Context $c, private bool $dry = false) {}

    // Stores a file once per key; returns its path on the disk.
    public function pdf(string $key, string $disk, string $dir, string $title, array $lines): string
    {
        return $this->store($key, $disk, $dir, 'pdf', fn () => self::makePdf($title, $lines));
    }

    public function png(string $key, string $disk, string $dir, array $lines, array $rgb = [214, 234, 248]): string
    {
        return $this->store($key, $disk, $dir, 'png', fn () => self::makePng($lines, $rgb));
    }

    private function store(string $key, string $disk, string $dir, string $ext, callable $bytes): string
    {
        $mkey = "file:{$key}";
        $existing = \Illuminate\Support\Facades\DB::table(Manifest::TABLE)->where('dataset', $this->c->m->dataset)->where('scenario_key', $mkey)->value('ref');
        if ($existing) {
            return substr($existing, strlen($disk) + 1);
        }
        $path = $dir.'/'.Str::uuid().'.'.$ext;
        if ($this->dry) {
            return $path;
        }
        Storage::disk($disk)->put($path, $bytes());
        $this->c->m->add($mkey, 'file', null, "{$disk}:{$path}");

        return $path;
    }

    public static function makePdf(string $title, array $lines): string
    {
        $esc = fn ($s) => str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], preg_replace('/[^\x20-\x7E]/', '', $s));
        $text = "BT /F1 11 Tf 50 790 Td 14 TL\n(".$esc(self::BANNER).") Tj T*\nT*\n/F1 15 Tf (".$esc($title).") Tj T*\n/F1 11 Tf T*\n";
        foreach ($lines as $l) {
            $text .= '('.$esc($l).") Tj T*\n";
        }
        $text .= "T*\n(".$esc(self::BANNER).") Tj\nET";
        $objs = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Length '.strlen($text)." >>\nstream\n{$text}\nendstream",
        ];
        $out = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objs as $i => $o) {
            $offsets[] = strlen($out);
            $out .= ($i + 1)." 0 obj\n{$o}\nendobj\n";
        }
        $xref = strlen($out);
        $out .= 'xref'."\n0 ".(count($objs) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $o) {
            $out .= str_pad((string) $o, 10, '0', STR_PAD_LEFT)." 00000 n \n";
        }

        return $out.'trailer << /Size '.(count($objs) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    }

    // 5x7 block font: enough letters for the sample labels.
    private const FONT = [
        'A' => ['01110', '10001', '10001', '11111', '10001', '10001', '10001'], 'B' => ['11110', '10001', '10001', '11110', '10001', '10001', '11110'],
        'C' => ['01111', '10000', '10000', '10000', '10000', '10000', '01111'], 'D' => ['11110', '10001', '10001', '10001', '10001', '10001', '11110'],
        'E' => ['11111', '10000', '10000', '11110', '10000', '10000', '11111'], 'F' => ['11111', '10000', '10000', '11110', '10000', '10000', '10000'],
        'G' => ['01111', '10000', '10000', '10011', '10001', '10001', '01111'], 'H' => ['10001', '10001', '10001', '11111', '10001', '10001', '10001'],
        'I' => ['11111', '00100', '00100', '00100', '00100', '00100', '11111'], 'J' => ['00111', '00010', '00010', '00010', '10010', '10010', '01100'],
        'K' => ['10001', '10010', '10100', '11000', '10100', '10010', '10001'], 'L' => ['10000', '10000', '10000', '10000', '10000', '10000', '11111'],
        'M' => ['10001', '11011', '10101', '10101', '10001', '10001', '10001'], 'N' => ['10001', '11001', '10101', '10011', '10001', '10001', '10001'],
        'O' => ['01110', '10001', '10001', '10001', '10001', '10001', '01110'], 'P' => ['11110', '10001', '10001', '11110', '10000', '10000', '10000'],
        'Q' => ['01110', '10001', '10001', '10001', '10101', '10010', '01101'], 'R' => ['11110', '10001', '10001', '11110', '10100', '10010', '10001'],
        'S' => ['01111', '10000', '10000', '01110', '00001', '00001', '11110'], 'T' => ['11111', '00100', '00100', '00100', '00100', '00100', '00100'],
        'U' => ['10001', '10001', '10001', '10001', '10001', '10001', '01110'], 'V' => ['10001', '10001', '10001', '10001', '10001', '01010', '00100'],
        'W' => ['10001', '10001', '10001', '10101', '10101', '10101', '01010'], 'X' => ['10001', '10001', '01010', '00100', '01010', '10001', '10001'],
        'Y' => ['10001', '10001', '01010', '00100', '00100', '00100', '00100'], 'Z' => ['11111', '00001', '00010', '00100', '01000', '10000', '11111'],
        '0' => ['01110', '10011', '10101', '10101', '11001', '10001', '01110'], '1' => ['00100', '01100', '00100', '00100', '00100', '00100', '01110'],
        '2' => ['01110', '10001', '00001', '00010', '00100', '01000', '11111'], '3' => ['11110', '00001', '00001', '01110', '00001', '00001', '11110'],
        '4' => ['00010', '00110', '01010', '10010', '11111', '00010', '00010'], '5' => ['11111', '10000', '11110', '00001', '00001', '10001', '01110'],
        '6' => ['01110', '10000', '10000', '11110', '10001', '10001', '01110'], '7' => ['11111', '00001', '00010', '00100', '01000', '01000', '01000'],
        '8' => ['01110', '10001', '10001', '01110', '10001', '10001', '01110'], '9' => ['01110', '10001', '10001', '01111', '00001', '00001', '01110'],
        '-' => ['00000', '00000', '00000', '11111', '00000', '00000', '00000'], '.' => ['00000', '00000', '00000', '00000', '00000', '01100', '01100'],
        ' ' => ['00000', '00000', '00000', '00000', '00000', '00000', '00000'],
    ];

    public static function makePng(array $lines, array $rgb): string
    {
        $scale = 4;
        $w = 640;
        $h = 40 + count($lines) * 7 * $scale * 2;
        $pix = array_fill(0, $h, array_fill(0, $w, $rgb));
        $ink = [33, 47, 61];
        foreach ($lines as $row => $line) {
            $line = strtoupper($line);
            $y0 = 20 + $row * 7 * $scale * 2;
            $x0 = max(10, (int) (($w - strlen($line) * 6 * $scale) / 2));
            foreach (str_split($line) as $i => $ch) {
                $glyph = self::FONT[$ch] ?? self::FONT[' '];
                foreach ($glyph as $gy => $bits) {
                    foreach (str_split($bits) as $gx => $on) {
                        if ($on !== '1') {
                            continue;
                        }
                        for ($dy = 0; $dy < $scale; $dy++) {
                            for ($dx = 0; $dx < $scale; $dx++) {
                                $x = $x0 + ($i * 6 + $gx) * $scale + $dx;
                                $y = $y0 + $gy * $scale + $dy;
                                if ($x < $w && $y < $h) {
                                    $pix[$y][$x] = $ink;
                                }
                            }
                        }
                    }
                }
            }
        }
        $raw = '';
        foreach ($pix as $r) {
            $raw .= "\0";
            foreach ($r as [$R, $G, $B]) {
                $raw .= chr($R).chr($G).chr($B);
            }
        }
        $chunk = fn ($type, $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0)).$chunk('IDAT', gzcompress($raw, 9)).$chunk('IEND', '');
    }
}
