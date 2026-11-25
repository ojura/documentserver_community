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

use OCA\DocumentServer\Document\ConvertCommand;
use OCA\DocumentServer\Document\DocumentFormat;
use OCA\DocumentServer\Document\TextOptions;
use Test\TestCase;

/**
 * What the app hands x2t. The text options are written only for the formats
 * that need them, so every other conversion gets the command it always had.
 */
class ConvertCommandTest extends TestCase {
	private function command(?TextOptions $options): \SimpleXMLElement {
		$command = new ConvertCommand('/tmp/in.csv', '/tmp/out/convert.jpg');
		$command->setTargetFormat(DocumentFormat::AVS_OFFICESTUDIO_FILE_IMAGE_JPG);
		$command->setTextOptions($options);
		return simplexml_load_string($command->serialize());
	}

	public function testWithoutTextOptionsTheCommandHasNoTextFields(): void {
		$xml = $this->command(null);

		$this->assertSame('/tmp/in.csv', (string)$xml->m_sFileFrom);
		$this->assertSame((string)DocumentFormat::AVS_OFFICESTUDIO_FILE_IMAGE_JPG, (string)$xml->m_nFormatTo);
		$this->assertCount(0, $xml->m_nCsvTxtEncoding);
		$this->assertCount(0, $xml->m_nCsvDelimiter);
		$this->assertCount(0, $xml->m_nCsvDelimiterChar);
	}

	public function testTheEncodingAndDelimiterReachX2t(): void {
		$xml = $this->command(new TextOptions(TextOptions::CODEPAGE_UTF8, TextOptions::DELIMITER_COMMA));

		$this->assertSame('46', (string)$xml->m_nCsvTxtEncoding);
		$this->assertSame('4', (string)$xml->m_nCsvDelimiter);
		$this->assertCount(0, $xml->m_nCsvDelimiterChar);
	}

	public function testAnEncodingAloneIsWrittenAlone(): void {
		$xml = $this->command(new TextOptions(TextOptions::CODEPAGE_UTF8));

		$this->assertSame('46', (string)$xml->m_nCsvTxtEncoding);
		$this->assertCount(0, $xml->m_nCsvDelimiter);
	}

	public function testADelimiterCharacterIsEscaped(): void {
		$xml = $this->command(new TextOptions(TextOptions::CODEPAGE_UTF8, 0, '<'));

		$this->assertSame('0', (string)$xml->m_nCsvDelimiter);
		$this->assertSame('<', (string)$xml->m_nCsvDelimiterChar);
	}
}
