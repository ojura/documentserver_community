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

namespace OCA\DocumentServer\Tests\Controller;

use OCA\DocumentServer\Controller\ConvertController;
use OCA\DocumentServer\Document\DocumentConversionException;
use OCA\DocumentServer\Document\DocumentStore;
use OCA\DocumentServer\Document\ParametersRequiredException;
use OCA\DocumentServer\Document\PasswordRequiredException;
use OCA\DocumentServer\OnlyOffice\URLDecoder;
use OCP\Files\File;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\TestCase;

/**
 * The conversion endpoint picks the scratch folder (doc_<id>) for a request
 * from the request's key. A plain (int) cast turned the connector's thumbnail
 * keys ("thumb_<revision>") into 0, so every thumbnail shared doc_0 and
 * rendered whichever document reached the folder first.
 */
class ConvertControllerTest extends TestCase {
	private ConvertController $controller;
	/** @var DocumentStore&\PHPUnit\Framework\MockObject\MockObject */
	private $documentStore;
	/** @var array[] the arguments of each convertFile() call, in call order */
	private array $conversions = [];
	private File $sourceFile;

	protected function setUp(): void {
		parent::setUp();

		$this->documentStore = $this->createMock(DocumentStore::class);
		$this->documentStore->method('convertFile')
			->willReturnCallback(function (int $documentId, File $file, string $sourceFormat, string $targetFormat): string {
				$this->conversions[] = [$documentId, $file, $sourceFormat, $targetFormat];
				return '/convert.' . $targetFormat;
			});

		$this->sourceFile = $this->createMock(File::class);
		$urlDecoder = $this->createMock(URLDecoder::class);
		$urlDecoder->method('getFileForUrl')->willReturn($this->sourceFile);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')
			->willReturnCallback(static fn (string $route, array $parameters): string
				=> 'https://cloud.example.com/open/' . $parameters['docId'] . '/' . $parameters['path']);

		$this->controller = new ConvertController(
			'documentserver_community',
			$this->createMock(IRequest::class),
			$this->documentStore,
			$urlDecoder,
			$urlGenerator
		);
	}

	/**
	 * Convert a document under $key and return the document id the controller
	 * used. The conversion and the url handed back to the connector must name
	 * the same folder.
	 */
	private function convertWithKey(string $key): int {
		$response = $this->controller->convert(false, 'https://cloud.example.com/file', 'jpg', 'docx', 'Document', $key);

		$documentId = end($this->conversions)[0];
		$this->assertIsInt($documentId);
		$this->assertSame(
			'https://cloud.example.com/open/' . $documentId . '/convert.jpg',
			$response->getData()['fileUrl']
		);
		return $documentId;
	}

	public function testNumericKeyIsTheDocumentId(): void {
		// what the connector sends for "Convert with ONLYOFFICE": the file's
		// editor key, so an open session's Editor.bin is used
		$this->assertSame(1546095031, $this->convertWithKey('1546095031'));
	}

	public static function privateKeys(): array {
		return [
			'thumbnail' => ['thumb_1546095031'],
			'short thumbnail key' => ['thumb_42'],
			'empty' => [''],
			'zero' => ['0'],
			'negative' => ['-5'],
			'leading zero' => ['007'],
			'numeric prefix' => ['12abc'],
			'leading space' => [' 12'],
			'overflowing' => ['99999999999999999999'],
			'unicode' => ['čćž'],
		];
	}

	/**
	 * Keys that are not a document id must never land in doc_0 - the folder
	 * every one of them used to share - nor in any folder an editing session
	 * could have, all of which have non-negative ids.
	 */
	#[DataProvider('privateKeys')]
	public function testOtherKeysGetAFolderOutsideTheEditorRange(string $key): void {
		$this->assertLessThan(0, $this->convertWithKey($key));
	}

	public function testThumbnailsOfDifferentFilesGetDifferentFolders(): void {
		$this->assertNotSame(
			$this->convertWithKey('thumb_1546095031'),
			$this->convertWithKey('thumb_1778228441')
		);
	}

	/**
	 * Two people previewing the same file at once send the same key. Sharing
	 * a folder would have the two converter runs write the same Editor.bin and
	 * convert.jpg under each other.
	 */
	public function testSameKeyTwiceDoesNotShareAFolder(): void {
		$this->assertNotSame(
			$this->convertWithKey('thumb_1546095031'),
			$this->convertWithKey('thumb_1546095031')
		);
	}

	public function testSameFormatIsNotConverted(): void {
		// the connector's health check: docx to docx under "check_<n>"
		$this->documentStore->expects($this->never())->method('convertFile');

		$response = $this->controller->convert(false, 'https://cloud.example.com/file', 'docx', 'docx', 'Document', 'check_12345');

		$this->assertSame('https://cloud.example.com/file', $response->getData()['fileUrl']);
	}

	public function testTheFileAndBothFormatsReachTheConversion(): void {
		// the connector's thumbnail request for a csv file
		$this->controller->convert(false, 'https://cloud.example.com/file', 'jpg', 'csv', 'Table', 'thumb_42');

		[, $file, $sourceFormat, $targetFormat] = end($this->conversions);
		$this->assertSame($this->sourceFile, $file);
		$this->assertSame('csv', $sourceFormat);
		$this->assertSame('jpg', $targetFormat);
	}

	public static function failures(): array {
		return [
			'a conversion error' => [new DocumentConversionException('x2t exited with status 88'), -3],
			'missing text options' => [new ParametersRequiredException('x2t needs the encoding'), -3],
			'a password' => [new PasswordRequiredException(90), -5],
		];
	}

	/**
	 * The connector reads {"error": <code>} and logs what it means; an
	 * exception escaping as a 500 is something it cannot interpret.
	 */
	#[DataProvider('failures')]
	public function testAFailedConversionIsAnErrorCode(DocumentConversionException $failure, int $code): void {
		$store = $this->createMock(DocumentStore::class);
		$store->method('convertFile')->willThrowException($failure);
		$urlDecoder = $this->createMock(URLDecoder::class);
		$urlDecoder->method('getFileForUrl')->willReturn($this->sourceFile);
		$controller = new ConvertController(
			'documentserver_community',
			$this->createMock(IRequest::class),
			$store,
			$urlDecoder,
			$this->createMock(IURLGenerator::class)
		);

		$response = $controller->convert(false, 'https://cloud.example.com/file', 'jpg', 'pdf', 'Paper', 'thumb_42');

		$this->assertSame(['error' => $code], $response->getData());
	}
}
