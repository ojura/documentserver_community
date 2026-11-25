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
use OCA\DocumentServer\Document\DocumentStore;
use OCA\DocumentServer\Document\TextOptions;
use OCA\DocumentServer\DocumentConverter;
use OCA\DocumentServer\LocalAppData;
use OCP\Files\File;
use OCP\Files\IAppData;
use OCP\Files\IRootFolder;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

/**
 * Which conversion the conversion endpoint gets. Going through Editor.bin
 * first fails for pdf, xps and vsdx, which x2t cannot open as an editor
 * document, and for txt, csv and tsv unless x2t is given an encoding and a
 * delimiter; so a file is converted directly, with the text defaults. Only an
 * open editing session's Editor.bin is converted from, because that is what
 * the conversion is meant to see.
 */
class DocumentStoreConvertTest extends TestCase {
	private const LOCAL_PATH = '/data/appdata_x/documentserver_community/doc_5';

	/** @var DocumentConverter&MockObject */
	private $converter;
	/** @var ISimpleFolder&MockObject */
	private $folder;
	private DocumentStore $store;

	protected function setUp(): void {
		parent::setUp();

		$this->converter = $this->createMock(DocumentConverter::class);
		$this->folder = $this->createMock(ISimpleFolder::class);
		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->willReturn($this->folder);
		$localAppData = $this->createMock(LocalAppData::class);
		$localAppData->method('getReadWriteLocalPath')
			->willReturnCallback(static function (ISimpleFolder $folder, callable $callback): void {
				$callback(self::LOCAL_PATH);
			});

		$this->store = new DocumentStore(
			$appData,
			$this->converter,
			$this->createMock(IConfig::class),
			$this->createMock(IRootFolder::class),
			$this->createMock(IUserManager::class),
			$localAppData,
			$this->createMock(IUserSession::class)
		);
	}

	private function sourceFile(): File {
		$file = $this->createMock(File::class);
		$file->method('fopen')->willReturnCallback(static fn () => fopen('php://memory', 'r'));
		return $file;
	}

	private function withEditorBin(bool $present): void {
		$this->folder->method('fileExists')->willReturnCallback(static fn (string $name): bool => $present && $name === 'Editor.bin');
	}

	public function testAFileIsConvertedDirectly(): void {
		$this->withEditorBin(false);
		$this->converter->expects($this->never())->method('runCommand');
		$this->converter->expects($this->once())->method('convertSource')->with(
			$this->callback('is_resource'),
			'pdf',
			self::LOCAL_PATH . '/convert.jpg',
			DocumentFormat::AVS_OFFICESTUDIO_FILE_IMAGE_JPG,
			null
		);

		$this->assertSame('/convert.jpg', $this->store->convertFile(-5, $this->sourceFile(), 'pdf', 'jpg'));
	}

	public function testATextFormatIsConvertedWithItsDefaults(): void {
		$this->withEditorBin(false);
		$this->converter->expects($this->once())->method('convertSource')->with(
			$this->anything(),
			'csv',
			$this->anything(),
			$this->anything(),
			$this->callback(static fn (?TextOptions $options): bool => $options !== null
				&& $options->getCodePage() === TextOptions::CODEPAGE_UTF8
				&& $options->getDelimiter() === TextOptions::DELIMITER_COMMA)
		);

		$this->store->convertFile(-5, $this->sourceFile(), 'csv', 'jpg');
	}

	public function testAnEditingSessionIsConvertedFromItsEditorBin(): void {
		$this->withEditorBin(true);
		$this->converter->expects($this->never())->method('convertSource');
		$this->converter->expects($this->once())->method('runCommand')->with(
			$this->callback(static function (ConvertCommand $command): bool {
				$xml = simplexml_load_string($command->serialize());
				return (string)$xml->m_sFileFrom === self::LOCAL_PATH . '/Editor.bin'
					&& (string)$xml->m_sFileTo === self::LOCAL_PATH . '/convert.pdf';
			})
		);

		$this->assertSame('/convert.pdf', $this->store->convertFile(1546095031, $this->sourceFile(), 'docx', 'pdf'));
	}

	public function testTheFileIsCopiedForTheOptionsDialog(): void {
		$copy = $this->createMock(ISimpleFile::class);
		$copy->expects($this->once())->method('putContent')->with($this->callback('is_resource'));
		$this->folder->expects($this->once())->method('newFile')->with('origin.csv')->willReturn($copy);

		$this->assertSame('origin.csv', $this->store->saveSourceFile(7, $this->sourceFile(), 'csv'));
	}
}
