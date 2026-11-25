<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2026 Juraj Oršulić <juraj.orsulic@fer.hr>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */
namespace OCA\DocumentServer\Tests\Document;

use OCA\DocumentServer\Document\TextOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\TestCase;

/**
 * x2t opens txt, csv and tsv only with an encoding, and csv and tsv only with a
 * delimiter as well. A conversion without an editor has nobody to ask, so it
 * uses fixed values; an editor sends back what the user picked.
 */
class TextOptionsTest extends TestCase {
	public static function textFormats(): array {
		return [
			'txt' => ['txt', TextOptions::CODEPAGE_UTF8, null],
			'csv' => ['csv', TextOptions::CODEPAGE_UTF8, TextOptions::DELIMITER_COMMA],
			'tsv' => ['tsv', TextOptions::CODEPAGE_UTF8, TextOptions::DELIMITER_TAB],
			'an upper-case extension' => ['CSV', TextOptions::CODEPAGE_UTF8, TextOptions::DELIMITER_COMMA],
		];
	}

	#[DataProvider('textFormats')]
	public function testATextFormatGetsAnEncodingAndItsDelimiter(string $format, int $codePage, ?int $delimiter): void {
		$options = TextOptions::defaultsFor($format);

		$this->assertNotNull($options);
		$this->assertSame($codePage, $options->getCodePage());
		$this->assertSame($delimiter, $options->getDelimiter());
		$this->assertNull($options->getDelimiterChar());
	}

	public static function otherFormats(): array {
		return [['docx'], ['xlsx'], ['pdf'], ['md'], ['html'], ['']];
	}

	#[DataProvider('otherFormats')]
	public function testOtherFormatsGetNone(string $format): void {
		$this->assertNull(TextOptions::defaultsFor($format));
	}

	public function testAnOpenWithoutACodePageHasNoOptions(): void {
		$this->assertNull(TextOptions::fromOpenCommand(['c' => 'open', 'id' => '5', 'format' => 'csv']));
	}

	public function testTheTextEditorSendsOnlyTheCodePage(): void {
		$options = TextOptions::fromOpenCommand(['c' => 'reopen', 'codepage' => 21]);

		$this->assertSame(21, $options->getCodePage());
		$this->assertNull($options->getDelimiter());
		$this->assertNull($options->getDelimiterChar());
	}

	public function testTheSpreadsheetEditorAddsTheDelimiter(): void {
		$options = TextOptions::fromOpenCommand(['c' => 'reopen', 'codepage' => 46, 'delimiter' => 2, 'delimiterChar' => null]);

		$this->assertSame(46, $options->getCodePage());
		$this->assertSame(2, $options->getDelimiter());
		$this->assertNull($options->getDelimiterChar());
	}

	public function testTheSpreadsheetEditorsListOfDelimitersGivesItsFirst(): void {
		// what the editor really sends after its import dialog
		$options = TextOptions::fromOpenCommand(['c' => 'reopen', 'codepage' => 46, 'delimiter' => [2], 'delimiterChar' => null]);
		$this->assertSame(2, $options->getDelimiter());

		$this->assertSame(2, TextOptions::fromOpenCommand(['codepage' => 46, 'delimiter' => [2, 4]])->getDelimiter());
		$this->assertNull(TextOptions::fromOpenCommand(['codepage' => 46, 'delimiter' => []])->getDelimiter());
	}

	public function testADelimiterOfTheUsersOwnIsKept(): void {
		$options = TextOptions::fromOpenCommand(['c' => 'reopen', 'codepage' => 46, 'delimiter' => 0, 'delimiterChar' => '|']);

		$this->assertSame(0, $options->getDelimiter());
		$this->assertSame('|', $options->getDelimiterChar());
	}

	public function testValuesThatAreNotNumbersAreNotPassedOn(): void {
		$this->assertNull(TextOptions::fromOpenCommand(['codepage' => 'utf-8']));

		$options = TextOptions::fromOpenCommand(['codepage' => '46', 'delimiter' => 'comma', 'delimiterChar' => '']);
		$this->assertSame(46, $options->getCodePage());
		$this->assertNull($options->getDelimiter());
		$this->assertNull($options->getDelimiterChar());
	}
}
