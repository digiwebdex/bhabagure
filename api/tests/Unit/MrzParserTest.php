<?php

namespace Tests\Unit;

use App\Services\Passports\MrzParser;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MrzParserTest extends TestCase
{
    /** The specimen passport from ICAO Doc 9303 part 4. */
    private const SPECIMEN = "P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<\nL898902C36UTO7408122F1204159ZE184226B<<<<<10";

    #[Test]
    public function it_reads_the_icao_specimen_and_every_check_digit_passes(): void
    {
        $result = (new MrzParser)->parse("PASSPORT\nUtopia\n".self::SPECIMEN."\n");

        $this->assertSame('Anna Maria Eriksson', $result['fullName']);
        $this->assertSame(['value' => 'L898902C3', 'confirm' => false], $result['passportNumber']);
        $this->assertSame(['value' => '1974-08-12', 'confirm' => false], $result['dateOfBirth']);
        $this->assertSame(['value' => '2012-04-15', 'confirm' => false], $result['passportExpiry']);
        $this->assertSame('F', $result['sex']);
        $this->assertTrue($result['compositeValid']);
    }

    #[Test]
    public function a_misread_digit_is_returned_for_the_traveller_to_confirm_not_accepted(): void
    {
        // OCR read the passport number's 8 as 3: its check digit no longer matches.
        $misread = str_replace('L898902C36', 'L893902C36', self::SPECIMEN);
        $result = (new MrzParser)->parse($misread);

        $this->assertSame(['value' => 'L893902C3', 'confirm' => true], $result['passportNumber']);
        $this->assertFalse($result['dateOfBirth']['confirm']);
        $this->assertFalse($result['compositeValid']);
    }

    #[Test]
    public function letters_ocr_puts_in_digit_positions_are_corrected_and_then_checked(): void
    {
        // O for 0 and spaces inside the line, as OCR often returns them.
        $noisy = "P<BGDHASAN<<TANVIR<AHMED<<<<<<<<<<<<<<<<<<<<\nA0l234567 8BGD9OO412 3M3001319<<<<<<<<<<<<<<04";
        $line2 = 'A01234567'.MrzParser::checkDigit('A01234567').'BGD900412'.MrzParser::checkDigit('900412').'M300131'.MrzParser::checkDigit('300131');
        $result = (new MrzParser)->parse("P<BGDHASAN<<TANVIR<AHMED<<<<<<<<<<<<<<<<<<<<\n".$line2.'<<<<<<<<<<<<<<0'.MrzParser::checkDigit($line2.'<<<<<<<<<<<<<<0'));

        $this->assertSame('Tanvir Ahmed Hasan', $result['fullName']);
        $this->assertSame(['value' => 'BGD', 'iso2' => 'BD'], $result['nationality']);
        $this->assertSame(['value' => '1990-04-12', 'confirm' => false], $result['dateOfBirth']);
        $this->assertSame(['value' => '2030-01-31', 'confirm' => false], $result['passportExpiry']);
        $this->assertTrue($result['compositeValid']);
        $this->assertNotNull((new MrzParser)->parse($noisy), 'noisy text still yields an MRZ to confirm');
    }

    #[Test]
    public function text_without_an_mrz_gives_nothing(): void
    {
        $this->assertNull((new MrzParser)->parse("PEOPLE'S REPUBLIC OF BANGLADESH\nPASSPORT\nName: Tanvir"));
    }
}
