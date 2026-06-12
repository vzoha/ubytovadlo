<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Command;

use App\Mrz\MrzParser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

/**
 * Dev-only OCR + parser test harness against a corpus of real photos.
 *
 * Expects a directory containing JPG/PNG files plus a `ground-truth.json`
 * file with the expected MrzResult fields per filename. Outputs per-field
 * accuracy (lastName/firstName/birthDate/documentNumber/nationalityCode/
 * documentType) so each pipeline tweak can be quantified.
 *
 * Not deployed: requires tesseract + imagemagick CLIs which live in the
 * dev container but not on the shared host. Production OCR happens
 * in the browser (tesseract.js); this command only validates the parser
 * and OCR pipeline locally.
 */
#[AsCommand(
    name: 'app:mrz:test',
    description: 'Spustí OCR + MrzParser nad korpusem fotek a vypíše per-field accuracy proti ground truth.',
)]
final class MrzTestCommand extends Command
{
    private const FIELDS = [
        'lastName',
        'firstName',
        'birthDate',
        'documentNumber',
        'nationalityCode',
        'documentType',
    ];

    public function __construct(private readonly MrzParser $parser)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::REQUIRED, 'Cesta ke korpusu (adresář s ground-truth.json)')
            ->addOption('verbose-raw', null, InputOption::VALUE_NONE, 'Vypsat raw OCR text pro každou variantu')
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'Spustit jen na 1 souboru (basename)')
            ->addOption('keep-preview', null, InputOption::VALUE_NONE, 'Ponechat předzpracované obrázky vedle vstupu (.preview-*.png)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dir = rtrim((string) $input->getArgument('path'), '/');
        $only = $input->getOption('only');
        $showRaw = (bool) $input->getOption('verbose-raw');
        $keepPreview = (bool) $input->getOption('keep-preview');

        if (!is_dir($dir)) {
            $io->error('Adresář neexistuje: ' . $dir);

            return Command::FAILURE;
        }

        $gtPath = $dir . '/ground-truth.json';
        if (!file_exists($gtPath)) {
            $io->error('Chybí ground-truth.json v: ' . $dir);

            return Command::FAILURE;
        }

        /** @var array<string, array<string, mixed>> $groundTruth */
        $groundTruth = json_decode((string) file_get_contents($gtPath), true, flags: JSON_THROW_ON_ERROR);

        $rows = [];
        $fieldHits = array_fill_keys(self::FIELDS, 0);
        $fieldMisses = array_fill_keys(self::FIELDS, 0);
        $overallOk = 0;
        $expectedFiles = $only ? [$only => $groundTruth[$only] ?? null] : $groundTruth;

        foreach ($expectedFiles as $filename => $expected) {
            if ($expected === null) {
                $io->warning('V ground-truth nenalezeno: ' . $filename);
                continue;
            }
            $full = $dir . '/' . $filename;
            if (!file_exists($full)) {
                $io->warning('Soubor chybí: ' . $full);
                continue;
            }

            $result = $this->ocrAndParse($full, $io, $showRaw, $keepPreview);

            $row = [substr($filename, 0, 22)];
            $allMatch = true;
            foreach (self::FIELDS as $field) {
                $expectedVal = $this->normalizeForCompare($expected[$field] ?? null);
                $actualVal = $this->normalizeForCompare($result['data'][$field] ?? null);
                $match = $expectedVal === $actualVal;

                if ($match) {
                    $fieldHits[$field]++;
                    $row[] = '<info>✓</info>';
                } else {
                    $fieldMisses[$field]++;
                    $allMatch = false;
                    $row[] = $actualVal === null
                        ? '<fg=red>—</fg=red>'
                        : '<fg=red>' . substr($actualVal, 0, 14) . '</fg=red>';
                }
            }
            $row[] = $result['variant'] ?? '-';
            if ($allMatch && $result['data'] !== null) {
                $overallOk++;
            }
            $rows[] = $row;
        }

        $io->newLine(2);
        $io->section('Per-rezervační výsledky');
        $io->table(
            ['Soubor', 'Příjm.', 'Jméno', 'Nar.', 'Doklad', 'Stát', 'Typ', 'Varianta'],
            $rows,
        );

        $io->section('Per-field úspěšnost');
        $perfRows = [];
        $total = \count($rows);
        foreach (self::FIELDS as $field) {
            $hits = $fieldHits[$field];
            $pct = $total > 0 ? round($hits / $total * 100, 1) : 0;
            $perfRows[] = [$field, "$hits / $total", $pct . ' %'];
        }
        $io->table(['Field', 'Hits', 'Accuracy'], $perfRows);
        $allOkPct = $total > 0 ? round($overallOk / $total * 100, 1) : 0;
        $io->writeln(sprintf('<info>Vše správně: %d / %d (%s %%)</info>', $overallOk, $total, $allOkPct));

        return Command::SUCCESS;
    }

    /**
     * @return array{data: ?array<string, mixed>, variant: ?string}
     */
    private function ocrAndParse(string $file, SymfonyStyle $io, bool $showRaw, bool $keepPreview): array
    {
        $io->writeln('<comment>→ ' . basename($file) . '</comment>');

        // OCR every preprocessing variant, then let the parser merge the reads
        // by per-field majority vote (parseMany). Voting beats winner-takes-all
        // because the check digits don't cover the name zone, so equally
        // confident variants can disagree on a surname/given name — the reading
        // most variants agree on wins each field. Orientation is a fallback:
        // we exhaust 0° first and only rotate when 0° yields nothing
        // checksum-solid, so correctly-oriented photos are never mis-rotated.
        $best = null;
        $bestConfidence = -1;
        $bestLabel = null;

        foreach ([0, 90, 180, 270] as $angle) {
            // 0° gets the full variant set; rotations are a last resort, so they
            // run a lean set (Otsu band + bottom only) to keep the worst case
            // fast. PSM 6 only — PSM 7 never wins on real MRZ blocks.
            $variants = $this->buildVariants($file, $keepPreview, $angle, lean: $angle !== 0);

            $texts = [];
            foreach ($variants as $name => $imagePath) {
                $text = $this->runTesseract($imagePath, 6);
                $texts[] = $text;

                if ($showRaw) {
                    $label = $angle === 0 ? sprintf('%s/PSM6', $name) : sprintf('%s/PSM6@%d°', $name, $angle);
                    $io->writeln('<comment>── ' . $label . ' ──</comment>');
                    $io->writeln($text === '' ? '(prázdné)' : $text);
                }

                if (!$keepPreview && $imagePath !== $file && file_exists($imagePath)) {
                    @unlink($imagePath);
                }
            }

            $result = $this->parser->parseMany($texts);
            if ($result !== null && $result->confidence > $bestConfidence) {
                $best = $result;
                $bestConfidence = $result->confidence;
                $bestLabel = $angle === 0 ? 'vote' : sprintf('vote@%d°', $angle);
            }

            // A checksum-solid upright vote makes rotation unnecessary.
            if ($bestConfidence >= 20) {
                break;
            }
        }

        return ['data' => $best?->toArray(), 'variant' => $bestLabel];
    }

    /**
     * Universal preprocessing pipeline. No per-image tweaks.
     *
     *  - auto-orient EXIF rotation
     *  - deskew 40% (text-aware rotation, fixes tilted cards/photos)
     *  - normalize width to ~2400 px
     *  - detect MRZ band by per-row edge density and crop to it
     *  - emit a small set of binarized variants for OCR
     *
     * Returns map of variant-name => prepared-image path.
     *
     * @return array<string, string>
     */
    private function buildVariants(string $original, bool $keepPreview, int $angle = 0, bool $lean = false): array
    {
        $base = ($keepPreview
            ? \dirname($original) . '/' . pathinfo($original, PATHINFO_FILENAME) . '.preview' . ($angle ? '_r' . $angle : '')
            : sys_get_temp_dir() . '/mrz_' . getmypid() . '_' . substr(sha1($original), 0, 10) . ($angle ? '_r' . $angle : ''));

        $deskewed = $this->prepareDeskewedImage($original, $base, $angle);
        if ($deskewed === null) {
            return [];
        }

        $variants = [];

        // Lean mode (used for rotation probes) emits a single bottom-band Otsu
        // variant — enough to detect a correct orientation cheaply without
        // ballooning the worst case on hard images.
        if ($lean) {
            $bottom = $base . '_bottom.png';
            $this->im([$deskewed, '-gravity', 'South', '-crop', '100x40%+0+0', '+repage', $bottom]);
            if (file_exists($bottom)) {
                $bottomOtsu = $base . '_bottom_otsu.png';
                $this->im([$bottom, '-resize', '3000x', '-unsharp', '0x1', '-threshold', '50%', $bottomOtsu]);
                if (file_exists($bottomOtsu)) {
                    $variants['bottom-otsu'] = $bottomOtsu;
                }
            }

            return $variants;
        }

        $bandCrop = $this->detectMrzBand($deskewed, $base);
        if ($bandCrop !== null) {
            $bandOtsu = $base . '_band_otsu.png';
            $this->im([$bandCrop, '-resize', '3000x', '-unsharp', '0x1', '-threshold', '50%', $bandOtsu]);
            if (file_exists($bandOtsu)) {
                $variants['band-otsu'] = $bandOtsu;
            }

            $bandAdaptive = $base . '_band_adaptive.png';
            $this->im([$bandCrop, '-resize', '3000x', '-unsharp', '0x1', '-lat', '25x25-5%', $bandAdaptive]);
            if (file_exists($bandAdaptive)) {
                $variants['band-adaptive'] = $bandAdaptive;
            }
        }

        // Fixed-geometry bottom band. The MRZ always sits in the lower part of
        // the document (TD1/TD2 cards, passport data page), so the bottom ~40%
        // is a reliable structural prior that rescues images where adaptive
        // band detection misfires — e.g. French CNIs, whose dense guilloche
        // background fools the row-density profile.
        $bottom = $base . '_bottom.png';
        $this->im([$deskewed, '-gravity', 'South', '-crop', '100x40%+0+0', '+repage', $bottom]);
        if (file_exists($bottom)) {
            $bottomOtsu = $base . '_bottom_otsu.png';
            $this->im([$bottom, '-resize', '3000x', '-unsharp', '0x1', '-threshold', '50%', $bottomOtsu]);
            if (file_exists($bottomOtsu)) {
                $variants['bottom-otsu'] = $bottomOtsu;
            }

            $bottomAdaptive = $base . '_bottom_adaptive.png';
            $this->im([$bottom, '-resize', '3000x', '-unsharp', '0x1', '-lat', '25x25-5%', $bottomAdaptive]);
            if (file_exists($bottomAdaptive)) {
                $variants['bottom-adaptive'] = $bottomAdaptive;
            }
        }

        // Whole-image fallbacks for when band detection and the fixed bottom
        // band both miss.
        $fullAdaptive = $base . '_full_adaptive.png';
        $this->im([$deskewed, '-unsharp', '0x1', '-lat', '25x25-5%', $fullAdaptive]);
        if (file_exists($fullAdaptive)) {
            $variants['full-adaptive'] = $fullAdaptive;
        }

        $fullOtsu = $base . '_full_otsu.png';
        $this->im([$deskewed, '-unsharp', '0x1', '-threshold', '50%', $fullOtsu]);
        if (file_exists($fullOtsu)) {
            $variants['full-otsu'] = $fullOtsu;
        }

        return $variants;
    }

    /**
     * Deskew + optional 90° orientation. `-deskew` corrects small tilt
     * (< ~10°); the optional `$angle` (0/90/180/270) handles a card shot
     * sideways or upside-down. The caller only requests a non-zero angle as a
     * fallback once every 0° variant has failed to parse, so correctly
     * oriented photos are never rotated.
     */
    private function prepareDeskewedImage(string $original, string $base, int $angle = 0): ?string
    {
        $final = $base . '_deskew.png';
        $args = [$original, '-auto-orient', '-colorspace', 'Gray'];
        if ($angle !== 0) {
            array_push($args, '-rotate', (string) $angle);
        }
        // Resize after rotation so the final image is always ~2400 px wide in
        // its corrected orientation, keeping band detection scale-consistent.
        array_push($args, '-resize', '2400x', '-deskew', '40%', '+repage', $final);

        $this->im($args);

        return file_exists($final) ? $final : null;
    }

    /**
     * Find the MRZ band by reducing the image to a 1-pixel-wide edge-density
     * profile (one value per row), smoothing it, and locating the bottom-most
     * contiguous high-density cluster. Crops to that range plus padding.
     *
     * MRZ rows have many vertical strokes per pixel-row, far more than the
     * sparse printed fields above them — so the bottom peak in the smoothed
     * profile is reliably the MRZ band, regardless of card aspect, fillers
     * or background size.
     *
     * Returns the cropped image path, or null if no band can be located
     * (we then fall back to whole-image variants).
     */
    private function detectMrzBand(string $deskewedPath, string $base): ?string
    {
        // Profile: per-row mean of inverted binarized image. Adaptive
        // threshold (lat) flattens slowly-varying backgrounds (wood, lighting
        // gradients) to uniform white; the negate then converts text strokes
        // to high pixel values so each row's mean reflects text density.
        $profilePath = $base . '_profile.pgm';
        $this->im([
            $deskewedPath,
            '-blur', '0x1',
            '-lat', '40x40-5%',
            '-negate',
            '-resize', '1x!',
            '-depth', '8',
            $profilePath,
        ]);
        if (!file_exists($profilePath)) {
            return null;
        }

        $profile = $this->readPgmColumn($profilePath);
        if ($profile === null || \count($profile) < 100) {
            return null;
        }

        $height = \count($profile);
        $smooth = $this->smoothProfile($profile, window: 21);

        $mean = array_sum($smooth) / $height;
        $variance = 0.0;
        foreach ($smooth as $v) {
            $variance += ($v - $mean) ** 2;
        }
        $stddev = sqrt($variance / $height);
        $threshold = $mean + 0.5 * $stddev;

        $clusters = [];
        $start = null;
        for ($y = 0; $y < $height; $y++) {
            if ($smooth[$y] >= $threshold) {
                if ($start === null) {
                    $start = $y;
                }
            } elseif ($start !== null) {
                $clusters[] = ['start' => $start, 'end' => $y - 1];
                $start = null;
            }
        }
        if ($start !== null) {
            $clusters[] = ['start' => $start, 'end' => $height - 1];
        }

        // Merge clusters within an MRZ inter-line gap. At 2400 px width the
        // inter-line gap on a TD1 (3-line, 30-char) MRZ runs ~40-60 px; we
        // bump the merge tolerance to 70 px so the 3 lines coalesce into a
        // single multi-peak cluster.
        $merged = [];
        foreach ($clusters as $c) {
            if ($merged !== [] && $c['start'] - $merged[\count($merged) - 1]['end'] <= 70) {
                $merged[\count($merged) - 1]['end'] = $c['end'];
            } else {
                $merged[] = $c;
            }
        }

        // Drop clusters too narrow to contain 2+ MRZ rows (single dense
        // printed lines, wood-grain spikes).
        $merged = array_values(array_filter(
            $merged,
            fn (array $c) => ($c['end'] - $c['start']) >= 40,
        ));
        if ($merged === []) {
            return null;
        }

        // MRZ has 2-3 dense rows of fixed-pitch text stacked tightly: in the
        // smoothed profile this shows up as multiple local maxima inside the
        // same cluster. A single printed line (PESEL number, header) gives
        // exactly one peak; wood-grain spikes are too narrow to survive the
        // earlier filter. Prefer clusters with >= 2 sub-peaks; among those,
        // pick the bottom-most.
        $multiLine = array_values(array_filter(
            $merged,
            fn (array $c) => $this->countSubPeaks($smooth, $c['start'], $c['end']) >= 2,
        ));
        if ($multiLine !== []) {
            $candidate = end($multiLine);
        } else {
            // Fallback: take cluster with highest integral.
            $candidate = null;
            $bestIntegral = -1.0;
            foreach ($merged as $c) {
                $integral = 0.0;
                for ($y = $c['start']; $y <= $c['end']; $y++) {
                    $integral += $smooth[$y];
                }
                if ($integral > $bestIntegral) {
                    $bestIntegral = $integral;
                    $candidate = $c;
                }
            }
        }
        if ($candidate === null) {
            return null;
        }

        $bandHeight = $candidate['end'] - $candidate['start'] + 1;
        if ($bandHeight < 40) {
            return null;
        }

        $pad = max(10, (int) ($bandHeight * 0.15));
        $top = max(0, $candidate['start'] - $pad);
        $bottom = min($height - 1, $candidate['end'] + $pad);
        $cropHeight = $bottom - $top + 1;

        [$imgW, $imgH] = getimagesize($deskewedPath) ?: [0, 0];
        if ($imgW === 0 || $imgH === 0) {
            return null;
        }

        $bandPath = $base . '_band.png';
        $this->im([
            $deskewedPath,
            '-crop', sprintf('%dx%d+0+%d', $imgW, $cropHeight, $top),
            '+repage',
            $bandPath,
        ]);

        return file_exists($bandPath) ? $bandPath : null;
    }

    /**
     * Read a 1-pixel-wide PGM into an array of ints, one per row.
     *
     * @return list<int>|null
     */
    private function readPgmColumn(string $path): ?array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $offset = 0;
        // P5 magic
        if (strncmp($raw, 'P5', 2) !== 0) {
            return null;
        }
        $offset = 2;
        $tokens = [];
        while (\count($tokens) < 3) {
            while ($offset < \strlen($raw) && ctype_space($raw[$offset])) {
                $offset++;
            }
            if ($offset < \strlen($raw) && $raw[$offset] === '#') {
                while ($offset < \strlen($raw) && $raw[$offset] !== "\n") {
                    $offset++;
                }
                continue;
            }
            $start = $offset;
            while ($offset < \strlen($raw) && !ctype_space($raw[$offset])) {
                $offset++;
            }
            $tokens[] = substr($raw, $start, $offset - $start);
        }
        if (\count($tokens) !== 3) {
            return null;
        }
        $offset++; // skip single whitespace after maxval

        [$width, $height, $maxval] = $tokens;
        if ((int) $width !== 1 || (int) $maxval > 255) {
            return null;
        }

        $bytes = substr($raw, $offset, (int) $height);
        if (\strlen($bytes) !== (int) $height) {
            return null;
        }

        return array_values(unpack('C*', $bytes) ?: []);
    }

    /**
     * Count local maxima in $smooth[$from..$to] separated by a meaningful
     * dip. A "peak" is a row whose value is the largest within a sliding
     * window and which sits above a baseline drawn from the cluster's min.
     *
     * @param list<float> $smooth
     */
    private function countSubPeaks(array $smooth, int $from, int $to): int
    {
        $slice = \array_slice($smooth, $from, $to - $from + 1);
        if (\count($slice) < 30) {
            return 0;
        }
        $min = min($slice);
        $max = max($slice);
        if ($max - $min < 5) {
            return 1;
        }
        $baseline = $min + ($max - $min) * 0.4;

        $peaks = 0;
        $inPeak = false;
        foreach ($slice as $v) {
            if (!$inPeak && $v >= $baseline) {
                $peaks++;
                $inPeak = true;
            } elseif ($inPeak && $v < $baseline) {
                $inPeak = false;
            }
        }

        return $peaks;
    }

    /**
     * Rolling-average smoothing for the row-density profile.
     *
     * @param list<int> $values
     *
     * @return list<float>
     */
    private function smoothProfile(array $values, int $window): array
    {
        $half = (int) ($window / 2);
        $n = \count($values);
        $result = [];
        for ($i = 0; $i < $n; $i++) {
            $from = max(0, $i - $half);
            $to = min($n - 1, $i + $half);
            $sum = 0;
            for ($j = $from; $j <= $to; $j++) {
                $sum += $values[$j];
            }
            $result[] = $sum / ($to - $from + 1);
        }

        return $result;
    }

    /**
     * @param list<string> $args
     */
    private function im(array $args): void
    {
        // ImageMagick (and, below, Tesseract) can abort with a fatal signal —
        // e.g. SIGFPE (8) on a degenerate intermediate image — which Symfony
        // surfaces as a ProcessSignaledException. A single bad variant must
        // never kill the whole pipeline: swallow it and let callers fall back
        // on the missing output file.
        $process = new Process(array_merge(['convert'], $args));
        $process->setTimeout(60);
        try {
            $process->run();
        } catch (\Throwable) {
            // leave output file absent; caller handles it
        }
    }

    private function runTesseract(string $imagePath, int $psm): string
    {
        $process = new Process([
            'tesseract', $imagePath, 'stdout',
            '-l', 'mrz',
            '--psm', (string) $psm,
        ]);
        $process->setTimeout(60);
        try {
            $process->run();

            return $process->getOutput();
        } catch (\Throwable) {
            return '';
        }
    }

    private function normalizeForCompare(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (\is_string($value)) {
            return mb_strtoupper(trim($value));
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return null;
    }
}
