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

namespace OCA\DocumentServer\Document;

/**
 * How x2t reads a plain text format: the character encoding and, for csv and
 * tsv, the delimiter.
 *
 * x2t will not open txt, csv or tsv without them; it exits with status 89
 * (ConverterBinary::STATUS_NEEDS_PARAMETERS) and writes nothing. An editor
 * answers that by asking the user and reopening with the choice, which
 * fromOpenCommand() reads. A conversion without an editor (a thumbnail, the
 * connector's "convert" action) uses defaultsFor(), the values ONLYOFFICE's
 * own conversion service falls back to: UTF-8, comma for csv, tab for tsv.
 *
 * The numbers are sdkjs's, not code pages: AscCommon.c_oAscCodePageUtf8 is 46
 * and AscCommon.c_oAscCsvDelimiter is {None: 0, Tab: 1, Semicolon: 2,
 * Colon: 3, Comma: 4, Space: 5}. The editors send their choice in the same
 * numbers, so they pass through to x2t unchanged.
 */
class TextOptions {
	public const CODEPAGE_UTF8 = 46;
	public const DELIMITER_TAB = 1;
	public const DELIMITER_COMMA = 4;

	/** @var int */
	private $codePage;
	/** @var int|null */
	private $delimiter;
	/** @var string|null */
	private $delimiterChar;

	public function __construct(int $codePage, ?int $delimiter = null, ?string $delimiterChar = null) {
		$this->codePage = $codePage;
		$this->delimiter = $delimiter;
		$this->delimiterChar = $delimiterChar;
	}

	/**
	 * The options a conversion without an editor uses for a source format, or
	 * null for the formats x2t opens without any.
	 */
	public static function defaultsFor(string $sourceFormat): ?self {
		switch (strtolower($sourceFormat)) {
			case 'txt':
				return new self(self::CODEPAGE_UTF8);
			case 'csv':
				return new self(self::CODEPAGE_UTF8, self::DELIMITER_COMMA);
			case 'tsv':
				return new self(self::CODEPAGE_UTF8, self::DELIMITER_TAB);
			default:
				return null;
		}
	}

	/**
	 * The options an editor sent with its open or reopen command, or null when
	 * it sent none - which it does until x2t has asked for them.
	 *
	 * The text editor sends only "codepage"; the spreadsheet editor adds
	 * "delimiter", and "delimiterChar" when the user typed a delimiter of
	 * their own. The spreadsheet editor sends "delimiter" as a list, [2] for
	 * semicolon; x2t reads one delimiter (given "2,4" it splits at the 2), so
	 * the first one is passed on.
	 */
	public static function fromOpenCommand(array $command): ?self {
		if (!isset($command['codepage']) || !is_numeric($command['codepage'])) {
			return null;
		}
		$delimiter = $command['delimiter'] ?? null;
		if (is_array($delimiter)) {
			$delimiter = reset($delimiter);
		}
		$delimiter = is_numeric($delimiter) ? (int)$delimiter : null;
		$delimiterChar = isset($command['delimiterChar']) && is_string($command['delimiterChar']) && $command['delimiterChar'] !== ''
			? $command['delimiterChar'] : null;
		return new self((int)$command['codepage'], $delimiter, $delimiterChar);
	}

	public function getCodePage(): int {
		return $this->codePage;
	}

	public function getDelimiter(): ?int {
		return $this->delimiter;
	}

	public function getDelimiterChar(): ?string {
		return $this->delimiterChar;
	}
}
