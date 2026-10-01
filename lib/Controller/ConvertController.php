<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2019 Robin Appelman <robin@icewind.nl>
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

namespace OCA\DocumentServer\Controller;

use OCA\DocumentServer\Document\DocumentConversionException;
use OCA\DocumentServer\Document\DocumentStore;
use OCA\DocumentServer\Document\PasswordRequiredException;
use OCA\DocumentServer\OnlyOffice\URLDecoder;
use OCA\DocumentServer\JSONResponse;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use OCP\IURLGenerator;

class ConvertController extends Controller {
	private $documentStore;
	private $urlDecoder;
	private $urlGenerator;

	public function __construct(
		string $appName,
		IRequest $request,
		DocumentStore $documentStore,
		URLDecoder $urlDecoder,
		IURLGenerator $urlGenerator
	) {
		parent::__construct($appName, $request);
		$this->documentStore = $documentStore;
		$this->urlDecoder = $urlDecoder;
		$this->urlGenerator = $urlGenerator;
	}


    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
	public function convert(bool $async, string $url, string $outputtype, string $filetype, string $title, string $key) {
		if ($outputtype === $filetype) {
			return new JSONResponse([
				'fileUrl' => $url,
				'percent' => 100,
				'endConvert' => true,
			]);
		} else {
			$documentId = $this->getScratchDocumentId($key);
			$documentFile = $this->urlDecoder->getFileForUrl($url);

			// A failed conversion must come back as a structured {"error": <code>}
			// body, not as an uncaught exception: this is a plain HTTP endpoint
			// with no other handler, so without this catch the converter's
			// exception escapes as a 500 the caller can't interpret. This endpoint
			// answers the connector over the server-side conversion API, whose
			// error codes differ from the editor's c_oAscError space used in the
			// socket replies: -5 incorrect password, -3 conversion error.
			try {
				$this->documentStore->getDocumentForEditor($documentId, $documentFile, $filetype);
				$this->documentStore->convert($documentId, $outputtype);
			} catch (PasswordRequiredException $e) {
				return new JSONResponse(['error' => -5]);
			} catch (DocumentConversionException $e) {
				return new JSONResponse(['error' => -3]);
			}

			$url = $this->urlGenerator->linkToRouteAbsolute(
				'documentserver_community.Document.documentFile', [
					'path' => 'convert.' . $outputtype,
					'docId' => $documentId,
				]
			);

			return new JSONResponse([
				'fileUrl' => $url,
				'percent' => 100,
				'endConvert' => true,
			]);
		}
	}

	/**
	 * The document id - and with it the doc_<id> folder - a conversion runs in.
	 *
	 * A key that is a document id, as the connector's editor-side conversions
	 * send, keeps naming that document: its folder may hold the Editor.bin of
	 * an open editing session, which the conversion is meant to reuse.
	 *
	 * Any other key gets a folder of its own. The connector's thumbnail
	 * requests send "thumb_<revision>", which a plain (int) cast turns into 0,
	 * so every thumbnail shared doc_0 and, because getDocumentForEditor()
	 * reuses the Editor.bin it finds there, rendered whichever file had reached
	 * the folder first. A hash of the key would not fix that properly: the
	 * connector's keys carry only 32 bits to begin with, and editing sessions
	 * take their ids from the same 32-bit range, so a collision would put a
	 * thumbnail into a live editing session's folder, or a later editing
	 * session onto a thumbnail's Editor.bin - and that session's changes would
	 * then be written to the file the thumbnail was made from.
	 *
	 * So the id is random and negative. The connector's editing sessions never
	 * get a negative id (their keys are crc32 values), two conversions never
	 * share a folder, not even for the same key, and the folder's name cannot
	 * be guessed. Nothing is lost by not reusing it: every conversion here runs
	 * synchronously, and the Cleanup job removes the folder on a later run.
	 */
	private function getScratchDocumentId(string $key): int {
		$documentId = (int)$key;
		if ($documentId > 0 && (string)$documentId === $key) {
			return $documentId;
		}

		return -random_int(1, PHP_INT_MAX);
	}
}
