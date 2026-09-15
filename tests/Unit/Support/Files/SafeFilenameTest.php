<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Files;

use App\Support\Files\SafeFilename;
use Tests\TestCase;

/**
 * The name a client sends is attacker-controlled input in a field everybody treats
 * as cosmetic (§57, §59).
 *
 * It reaches two places: `files.original_name`, which admin tables, exports and
 * logs render as text, and the `Content-Disposition` header of a download, which a
 * browser parses. Those are different jobs with different failure modes — a CR in
 * the second one splits a response, a path separator in the first one becomes a
 * traversal the moment anything joins the name to a directory — so the two answers
 * are two methods, and each is checked against the inputs that break it.
 *
 * Everything here is a pure string function, which is why it is in the Unit suite:
 * no database, no HTTP, and no way for a fixture to hide the case that matters.
 */
final class SafeFilenameTest extends TestCase
{
    public function test_a_plain_name_is_left_alone(): void
    {
        $this->assertSame('report.pdf', SafeFilename::forStorage('report.pdf'));
        $this->assertSame('Annual Report 2026.docx', SafeFilename::forStorage('Annual Report 2026.docx'));
    }

    public function test_non_ascii_survives_untouched(): void
    {
        // A parent in Lagos or Lyon uploads in their own language. Transliterating
        // or dropping these characters would hand back a name that is not theirs.
        $this->assertSame('Résultats scolaires.pdf', SafeFilename::forStorage('Résultats scolaires.pdf'));
        $this->assertSame('עברית.txt', SafeFilename::forStorage('עברית.txt'));
    }

    public function test_path_separators_cannot_survive_into_a_stored_name(): void
    {
        foreach ([
            '../../config/app.php' => 'config app.php',
            '/etc/passwd' => 'etc passwd',
            'C:\\boot.ini' => 'C: boot.ini',
            '..\\..\\windows\\system32\\config\\sam' => 'windows system32 config sam',
        ] as $input => $expected) {
            $this->assertSame($expected, SafeFilename::forStorage($input));
        }
    }

    public function test_a_stored_name_never_contains_a_separator(): void
    {
        $nasty = [
            '../../etc/passwd',
            'a/b/c/d.pdf',
            "C:\\Users\\x\\secret.docx",
            '/leading/slash.txt',
            'trailing/slash/',
        ];

        foreach ($nasty as $name) {
            $sanitised = SafeFilename::forStorage($name);

            $this->assertStringNotContainsString('/', $sanitised, $name);
            $this->assertStringNotContainsString('\\', $sanitised, $name);
        }
    }

    public function test_control_characters_are_removed_rather_than_escaped(): void
    {
        // An escaped CR is still a CR to whatever parses the header next.
        $sanitised = SafeFilename::forDownload("report.pdf\r\nX-Injected: yes");

        $this->assertStringNotContainsString("\r", $sanitised);
        $this->assertStringNotContainsString("\n", $sanitised);
        $this->assertSame('report.pdfX-Injected: yes', $sanitised);
    }

    public function test_a_nul_byte_cannot_truncate_the_name(): void
    {
        // The classic C-string trick: a name that ends at the NUL for one layer and
        // continues for the next.
        $sanitised = SafeFilename::forStorage("photo.jpg\x00.php");

        $this->assertStringNotContainsString("\x00", $sanitised);
        $this->assertSame('photo.jpg.php', $sanitised);
    }

    public function test_quotes_are_removed_because_they_end_a_header_parameter(): void
    {
        $this->assertSame('say hello.pdf', SafeFilename::forStorage('say "hello".pdf'));

        // Removed, not replaced with a space: the quote is meaningless in a
        // filename, and turning `it's.pdf` into `it is.pdf` would invent a word
        // break the person who typed the name did not put there.
        $this->assertSame('its.pdf', SafeFilename::forStorage("it's.pdf"));
    }

    public function test_a_semicolon_cannot_add_a_header_parameter_of_its_own(): void
    {
        // `; filename=` inside a Content-Disposition value is how a client supplies
        // a second name for the same response.
        $this->assertSame('ab.pdf', SafeFilename::forDownload('a;b.pdf'));
        $this->assertStringNotContainsString(
            ';',
            SafeFilename::forDownload('report.pdf; filename=evil.exe'),
        );
    }

    public function test_a_name_cannot_become_a_hidden_file(): void
    {
        $this->assertSame('htaccess', SafeFilename::forStorage('.htaccess'));
        $this->assertSame('bashrc', SafeFilename::forStorage('.bashrc'));
        $this->assertSame('pdf', SafeFilename::forStorage('..pdf'));
    }

    public function test_a_name_that_is_only_separators_falls_back_rather_than_going_empty(): void
    {
        foreach (['', '...', '   ', "\r\n", '/', '\\', '"'] as $input) {
            $this->assertSame(SafeFilename::FALLBACK, SafeFilename::forStorage($input));
        }
    }

    public function test_a_long_name_is_shortened_without_losing_its_extension(): void
    {
        $name = str_repeat('a', 300).'.pdf';

        $sanitised = SafeFilename::forStorage($name);

        // The extension is the part a person acts on: `rep….pdf` says what the file
        // is, `report` truncated at the column limit does not.
        $this->assertLessThanOrEqual(SafeFilename::MAX_LENGTH, mb_strlen($sanitised));
        $this->assertSame(SafeFilename::MAX_LENGTH, mb_strlen($sanitised));
        $this->assertStringEndsWith('….pdf', $sanitised);
    }

    public function test_truncation_is_multibyte_safe(): void
    {
        $name = str_repeat('é', 300).'.pdf';

        $sanitised = SafeFilename::forStorage($name);

        // Cutting at a byte boundary would leave half a character, which some
        // browsers render as a replacement glyph in the saved filename.
        $this->assertSame(SafeFilename::MAX_LENGTH, mb_strlen($sanitised));
        $this->assertStringEndsWith('….pdf', $sanitised);
        $this->assertSame($sanitised, mb_convert_encoding($sanitised, 'UTF-8', 'UTF-8'));
    }

    public function test_an_absurd_extension_is_not_preserved_at_the_price_of_the_name(): void
    {
        $name = str_repeat('b', 250).'.'.str_repeat('x', 40);

        $sanitised = SafeFilename::forStorage($name);

        $this->assertSame(SafeFilename::MAX_LENGTH, mb_strlen($sanitised));
        $this->assertStringNotContainsString('….', $sanitised);
    }

    public function test_a_name_that_fits_is_never_marked_as_truncated(): void
    {
        $name = str_repeat('c', SafeFilename::MAX_LENGTH - 4).'.pdf';

        $this->assertSame($name, SafeFilename::forStorage($name));
    }

    public function test_sanitising_is_idempotent(): void
    {
        // A name can pass through the upload path and later through an import or an
        // export. If the second pass changed it, two parts of the system would be
        // displaying different names for the same row, and nobody would be able to
        // see which one was right.
        $inputs = [
            '../../config/app.php',
            '/etc/passwd',
            'C:\\boot.ini',
            '.htaccess',
            "report\r\n.pdf",
            str_repeat('a', 300).'.pdf',
            'Résultats scolaires.pdf',
            'plain.pdf',
        ];

        foreach ($inputs as $input) {
            $once = SafeFilename::forStorage($input);

            $this->assertSame($once, SafeFilename::forStorage($once), $input);
            $this->assertSame(
                SafeFilename::forDownload($once),
                SafeFilename::forDownload(SafeFilename::forDownload($once)),
                $input,
            );
        }
    }

    public function test_the_stored_form_never_starts_or_ends_with_a_space(): void
    {
        foreach (['  padded.pdf  ', '/leading/slash.txt', '../../x.pdf'] as $input) {
            $sanitised = SafeFilename::forStorage($input);

            $this->assertSame(trim($sanitised), $sanitised, $input);
        }
    }

    public function test_the_download_form_is_at_least_as_strict_as_the_stored_form(): void
    {
        $input = 'report; with "quotes" and \\slashes/.pdf';

        $stored = SafeFilename::forStorage($input);
        $download = SafeFilename::forDownload($input);

        // The download name applies every storage rule and then removes the
        // characters that are meaningful inside a header parameter.
        $this->assertStringNotContainsString(';', $download);
        $this->assertStringNotContainsString('"', $download);
        $this->assertStringNotContainsString('"', $stored);
        $this->assertLessThanOrEqual(strlen($stored), strlen($download));
    }
}
