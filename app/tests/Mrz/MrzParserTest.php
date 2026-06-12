<?php

declare(strict_types=1);

namespace App\Tests\Mrz;

use App\Enum\DocumentType;
use App\Mrz\MrzParser;
use PHPUnit\Framework\TestCase;

class MrzParserTest extends TestCase
{
    private MrzParser $parser;

    protected function setUp(): void
    {
        $this->parser = new MrzParser();
    }

    public function testParseTd3Passport(): void
    {
        $mrz = "P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<\nL898902C36UTO7408122F1204159ZE184226B<<<<<10";

        $result = $this->parser->parse($mrz);

        self::assertNotNull($result);
        self::assertSame('ERIKSSON', $result->lastName);
        self::assertSame('ANNA MARIA', $result->firstName);
        self::assertSame('1974-08-12', $result->birthDate->format('Y-m-d'));
        self::assertSame('F', $result->sex);
        self::assertSame('UTO', $result->nationalityCode);
        self::assertSame(DocumentType::PASSPORT, $result->documentType);
        self::assertSame('L898902C3', $result->documentNumber);
        self::assertSame('2012-04-15', $result->expiryDate?->format('Y-m-d'));
    }

    public function testParseTd1IdCard(): void
    {
        $mrz = "I<UTOD231458907<<<<<<<<<<<<<<<\n7408122F1204159UTO<<<<<<<<<<<6\nERIKSSON<<ANNA<MARIA<<<<<<<<<<";

        $result = $this->parser->parse($mrz);

        self::assertNotNull($result);
        self::assertSame('ERIKSSON', $result->lastName);
        self::assertSame('ANNA MARIA', $result->firstName);
        self::assertSame('1974-08-12', $result->birthDate->format('Y-m-d'));
        self::assertSame('F', $result->sex);
        self::assertSame('UTO', $result->nationalityCode);
        self::assertSame(DocumentType::ID_CARD, $result->documentType);
        self::assertSame('D23145890', $result->documentNumber);
    }

    public function testParseTd2(): void
    {
        $mrz = "I<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<\nD231458907UTO7408122F1204159<<<<<<<6";

        $result = $this->parser->parse($mrz);

        self::assertNotNull($result);
        self::assertSame('ERIKSSON', $result->lastName);
        self::assertSame('ANNA MARIA', $result->firstName);
        self::assertSame('D23145890', $result->documentNumber);
        self::assertSame(DocumentType::ID_CARD, $result->documentType);
    }

    public function testGermanNationalityMapping(): void
    {
        $mrz = "P<D<<MUELLER<<HANS<<<<<<<<<<<<<<<<<<<<<<<<<<<\nC01X00T478D<<6408125M2010315<<<<<<<<<<<<<<04";

        $result = $this->parser->parse($mrz);

        self::assertNotNull($result);
        self::assertSame('DEU', $result->nationalityCode);
    }

    public function testReturnsNullForGarbage(): void
    {
        self::assertNull($this->parser->parse('Hello World'));
        self::assertNull($this->parser->parse(''));
        self::assertNull($this->parser->parse("ABCDEF\nGHIJKL"));
    }

    public function testToArrayFormat(): void
    {
        $mrz = "P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<\nL898902C36UTO7408122F1204159ZE184226B<<<<<10";

        $result = $this->parser->parse($mrz);
        self::assertNotNull($result);

        $arr = $result->toArray();
        self::assertSame('ERIKSSON', $arr['lastName']);
        self::assertSame('ANNA MARIA', $arr['firstName']);
        self::assertSame('1974-08-12', $arr['birthDate']);
        self::assertSame('passport', $arr['documentType']);
        self::assertSame('L898902C3', $arr['documentNumber']);
    }

    public function testPassportWithSingleName(): void
    {
        $mrz = "P<UTOALI<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<\nA123456786UTO8501011M2512311<<<<<<<<<<<<<<00";

        $result = $this->parser->parse($mrz);

        self::assertNotNull($result);
        self::assertSame('ALI', $result->lastName);
        self::assertSame('', $result->firstName);
        self::assertSame('M', $result->sex);
    }

    public function testOcrNoiseInMrz(): void
    {
        $mrz = "Some header text\nP<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<\nL898902C36UTO7408122F1204159ZE184226B<<<<<10\nFooter noise";

        $result = $this->parser->parse($mrz);

        self::assertNotNull($result);
        self::assertSame('ERIKSSON', $result->lastName);
    }

    public function testCzechPassport(): void
    {
        $mrz = "P<CZENOVAK<<JAN<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<\n99003853<1CZE8503150M27121782<<<<<<<<<<<<<<08";

        $result = $this->parser->parse($mrz);

        self::assertNotNull($result);
        self::assertSame('NOVAK', $result->lastName);
        self::assertSame('JAN', $result->firstName);
        self::assertSame('CZE', $result->nationalityCode);
        self::assertSame('1985-03-15', $result->birthDate->format('Y-m-d'));
        self::assertSame(DocumentType::PASSPORT, $result->documentType);
    }

    public function testSlovakIdCard(): void
    {
        $mrz = "I<SVKSA123456781<<<<<<<<<<<<<<<\n9001015F3012319SVK<<<<<<<<<<<2\nHORVATHOVA<<MARIA<<<<<<<<<<<<<";

        $result = $this->parser->parse($mrz);

        self::assertNotNull($result);
        self::assertSame('HORVATHOVA', $result->lastName);
        self::assertSame('MARIA', $result->firstName);
        self::assertSame('SVK', $result->nationalityCode);
        self::assertSame(DocumentType::ID_CARD, $result->documentType);
        self::assertSame('SA1234567', $result->documentNumber);
    }

    public function testOcrKForFillerInTd1NameLine(): void
    {
        $mrz = "IDCHEE5310932<9KKKKKKKKKKKKKKKK\n8809058M3203215CHEKKKKKKKKKKKKK2\nEICHKKANDREKSYLVAINKFLORIANKKK";

        $result = $this->parser->parse($mrz);

        self::assertNotNull($result);
        self::assertSame('EICH', $result->lastName);
        self::assertSame('ANDRE SYLVAIN FLORIAN', $result->firstName);
        self::assertSame('CHE', $result->nationalityCode);
        self::assertSame('1988-09-05', $result->birthDate->format('Y-m-d'));
        self::assertSame('M', $result->sex);
        self::assertSame('E5310932', $result->documentNumber);
    }

    public function testOcrPartialKForFillerInName(): void
    {
        $mrz = "IDCHEE5310932<9<<<<<<<<<<<<<<<\n8809058M3203215CHE<<<<<<<<<<<<2\nEICH<<ANDREKSYLVAINKFLORIAN<<<";

        $result = $this->parser->parse($mrz);

        self::assertNotNull($result);
        self::assertSame('EICH', $result->lastName);
        self::assertSame('ANDRE SYLVAIN FLORIAN', $result->firstName);
    }

    public function testSwissIdCardRealMrz(): void
    {
        $mrz = "IDCHEE5310932<9<<<<<<<<<<<<<<<\n8809058M3203215CHE<<<<<<<<<<<<2\nEICH<<ANDRE<SYLVAIN<FLORIAN<<<";

        $result = $this->parser->parse($mrz);

        self::assertNotNull($result);
        self::assertSame('EICH', $result->lastName);
        self::assertSame('ANDRE SYLVAIN FLORIAN', $result->firstName);
        self::assertSame('CHE', $result->nationalityCode);
        self::assertSame(DocumentType::ID_CARD, $result->documentType);
        self::assertSame('E5310932', $result->documentNumber);
        self::assertSame('1988-09-05', $result->birthDate->format('Y-m-d'));
        self::assertSame('M', $result->sex);
        self::assertSame('2032-03-21', $result->expiryDate?->format('Y-m-d'));
    }

    public function testRealNameWithKIsPreserved(): void
    {
        $mrz = "P<UTOKOWALSKI<<KRZYSZTOF<<<<<<<<<<<<<<<<<<<<<\nA123456786UTO8501011M2512311<<<<<<<<<<<<<<00";

        $result = $this->parser->parse($mrz);

        self::assertNotNull($result);
        self::assertSame('KOWALSKI', $result->lastName);
        self::assertSame('KRZYSZTOF', $result->firstName);
    }

    public function testParseManyVotesGivenNamePerField(): void
    {
        // The trommler case: three variants read GERHARD<LUDWIG, one misreads
        // the given name as LUDWILG. Per-field voting must recover LUDWIG even
        // though all four reads are equally check-digit-confident.
        $good = "I<UTOD231458907<<<<<<<<<<<<<<<\n7408122F1204159UTO<<<<<<<<<<<6\nTROMMLER<<GERHARD<LUDWIG<<<<<<";
        $bad = "I<UTOD231458907<<<<<<<<<<<<<<<\n7408122F1204159UTO<<<<<<<<<<<6\nTROMMLER<<GERHARD<LUDWILG<<<<<";

        $result = $this->parser->parseMany([$bad, $good, $good, $good]);

        self::assertNotNull($result);
        self::assertSame('TROMMLER', $result->lastName);
        self::assertSame('GERHARD LUDWIG', $result->firstName);
    }

    public function testParseManyIgnoresGarbageVariants(): void
    {
        // A single solid read plus unparseable noise must still parse.
        $good = "P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<\nL898902C36UTO7408122F1204159ZE184226B<<<<<10";

        $result = $this->parser->parseMany(['', 'garbage noise', $good]);

        self::assertNotNull($result);
        self::assertSame('ERIKSSON', $result->lastName);
    }

    public function testStripsSpaceSeparatedLeadingNoiseOnNameLine(): void
    {
        // Every preprocessing variant of a real ID prefixed the TD1 name line
        // with a stray, space-separated token ("T TROMMLER<<…"). Gluing across
        // the space yielded "TTROMMLER"; the MRZ payload is the longest token.
        $mrz = "I<UTOD231458907<<<<<<<<<<<<<<<\n7408122F1204159UTO<<<<<<<<<<<6\nT TROMMLER<<GERHARD<LUDWIG<<<<";

        $result = $this->parser->parse($mrz);

        self::assertNotNull($result);
        self::assertSame('TROMMLER', $result->lastName);
        self::assertSame('GERHARD LUDWIG', $result->firstName);
    }

    public function testRepairsSingleDigitDobMisread(): void
    {
        // The naether case: a DOB digit (5) misread as 0 — 751209 -> 701209 —
        // while its check digit (8) still matches the true date. Constrained,
        // date-aware check-digit repair must recover 1975-12-09 and not pick a
        // competing implausible date.
        $mrz = "I<DEUD231458907<<<<<<<<<<<<<<<\n7012098F2501017DEU<<<<<<<<<<<0\nNAETHER<<JULIA<<<<<<<<<<<<<<<<";

        $result = $this->parser->parse($mrz);

        self::assertNotNull($result);
        self::assertSame('NAETHER', $result->lastName);
        self::assertSame('1975-12-09', $result->birthDate->format('Y-m-d'));
    }

    public function testStillParsesCleanDobReference(): void
    {
        // Guard against the repair logic perturbing an already-valid date.
        $mrz = "P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<\nL898902C36UTO7408122F1204159ZE184226B<<<<<10";

        $result = $this->parser->parse($mrz);

        self::assertNotNull($result);
        self::assertSame('1974-08-12', $result->birthDate->format('Y-m-d'));
    }

    public function testFrenchNationalIdCard(): void
    {
        // Pre-2021 French CNI 2×36 national format (non-ICAO layout).
        $mrz = "IDFRAMURGOCI<<<<<<<<<<<<<<<<<<067061\n2103678517026MONICA<ADELINA8803090F3";

        $result = $this->parser->parse($mrz);

        self::assertNotNull($result);
        self::assertSame('MURGOCI', $result->lastName);
        self::assertSame('MONICA ADELINA', $result->firstName);
        self::assertSame('1988-03-09', $result->birthDate->format('Y-m-d'));
        self::assertSame('F', $result->sex);
        self::assertSame('FRA', $result->nationalityCode);
        self::assertSame(DocumentType::ID_CARD, $result->documentType);
        self::assertSame('210367851702', $result->documentNumber);
        self::assertNull($result->expiryDate);
    }

    public function testFrenchNationalIdCardWithOcrNoise(): void
    {
        // Real OCR output: stray leading/trailing characters around both lines.
        // The doc-number check digit re-anchors the field grid.
        $mrz = "IDFRAMURGOCI<<<<<<<<<<<<<<<<<<067061 BERG\n0 2103678517026MONICA<ADELINA8803090F3IBIEBINB";

        $result = $this->parser->parse($mrz);

        self::assertNotNull($result);
        self::assertSame('MURGOCI', $result->lastName);
        self::assertSame('1988-03-09', $result->birthDate->format('Y-m-d'));
        self::assertSame('FRA', $result->nationalityCode);
        self::assertSame('210367851702', $result->documentNumber);
    }
}
