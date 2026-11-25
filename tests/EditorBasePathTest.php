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
namespace OCA\DocumentServer\Tests;

use OCA\DocumentServer\EditorBasePath;
use OCP\IConfig;
use Test\TestCase;

/**
 * An editor's page has to load from under 3rdparty/, where its relative sdkjs/
 * paths resolve to files this app serves. api.js takes that location from
 * extensionParams, which the api.js this app serves defines itself.
 */
class EditorBasePathTest extends TestCase {
	private function script(string $documentServerUrl): string {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->with('onlyoffice', 'DocumentServerUrl')->willReturn($documentServerUrl);
		return (new EditorBasePath($config))->extensionParamsScript();
	}

	public function testTheEditorsLoadFromUnder3rdparty(): void {
		$script = $this->script('https://cloud.example.com/index.php/apps/documentserver_community/');

		$this->assertSame(
			'var extensionParams = {"url":"https://cloud.example.com/index.php/apps/documentserver_community/3rdparty/onlyoffice/documentserver/web-apps/"};' . "\n",
			$script
		);
	}

	public function testAUrlWithoutTheTrailingSlashGivesTheSamePath(): void {
		$this->assertStringContainsString(
			'"url":"https://cloud.example.com/apps/documentserver_community/3rdparty/onlyoffice/documentserver/web-apps/"',
			$this->script('https://cloud.example.com/apps/documentserver_community')
		);
	}

	public function testAnotherDocumentServerIsLeftAlone(): void {
		$this->assertSame('', $this->script('https://office.example.com/'));
		$this->assertSame('', $this->script(''));
	}
}
