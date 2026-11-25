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

namespace OCA\DocumentServer;

use OCP\IConfig;

/**
 * Where the editors' pages are loaded from.
 *
 * api.js puts an editor's page under extensionParams.url when that is set,
 * and otherwise under the directory api.js itself was loaded from. The
 * connector loads api.js through the /web-apps/ route, and a page under that
 * route resolves its relative sdkjs/ paths to a location this app does not
 * serve, so every editor fails to start. Under the 3rdparty/ route the same
 * relative paths resolve to files the app does serve.
 *
 * StaticController::webApps() defines extensionParams at the top of the api.js
 * it serves. Nextcloud up to 34 also let an app add it to the page's settings
 * through the \OCP\Config "js" hook, which Nextcloud 35 no longer emits.
 */
class EditorBasePath {
	private $config;

	public function __construct(IConfig $config) {
		$this->config = $config;
	}

	/**
	 * The script that defines extensionParams, or an empty string when the
	 * connector does not use this app's document server.
	 */
	public function extensionParamsScript(): string {
		$documentServerUrl = $this->config->getAppValue('onlyoffice', 'DocumentServerUrl');
		if (!strpos($documentServerUrl, 'apps/documentserver_community')) {
			return '';
		}
		$url = rtrim($documentServerUrl, '/') . '/3rdparty/onlyoffice/documentserver/web-apps/';
		return 'var extensionParams = ' . json_encode(['url' => $url], JSON_UNESCAPED_SLASHES) . ";\n";
	}
}
