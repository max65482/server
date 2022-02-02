<?php

declare(strict_types=1);

/**
 * @copyright 2022 Christopher Ng <chrng8@gmail.com>
 *
 * @author Christopher Ng <chrng8@gmail.com>
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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\DAV\UserMigration;

use Exception;
use function Safe\fopen;
use OC\Files\Filesystem;
use OC\Files\View;
use OCA\DAV\AppInfo\Application;
use OCA\DAV\CalDAV\CalDavBackend;
use OCA\DAV\CalDAV\ICSExportPlugin\ICSExportPlugin;
use OCA\DAV\CalDAV\Plugin as CalDAVPlugin;
use OCA\DAV\Connector\Sabre\CachingTree;
use OCA\DAV\Connector\Sabre\ExceptionLoggerPlugin;
use OCA\DAV\Connector\Sabre\Server as SabreDavServer;
use OCA\DAV\RootCollection;
use OCP\Calendar\ICalendar;
use OCP\Calendar\IManager as ICalendarManager;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IUser;
use Psr\Log\LoggerInterface;
use Sabre\CalDAV\Plugin;
use Sabre\DAV\Exception\BadRequest;
use Sabre\VObject\Component;
use Sabre\VObject\Property;
use Sabre\VObject\Reader as VObjectReader;
use Sabre\VObject\UUIDUtil;
use Safe\Exceptions\FilesystemException;
use Symfony\Component\Console\Output\OutputInterface;

class CalendarMigrator {

	/** @var ICalendarManager */
	private $calendarManager;

	/** @var SabreDavServer */
	private $sabreDavServer;

	/** @var CalDavBackend */
	private $calDavBackend;

	// ICSExportPlugin is injected to use the mergeObjects() method and is not to be used as a SabreDAV server plugin
	/** @var ICSExportPlugin */
	private $icsExportPlugin;

	/** @var IConfig */
	private $config;

	/** @var LoggerInterface */
	private $logger;

	/** @var IL10N */
	private $l10n;

	/** @var string */
	public const USERS_URI_ROOT = 'principals/users/';

	/** @var string */
	public const FILENAME_EXT = '.ics';

	public function __construct(
		ICalendarManager $calendarManager,
		CalDavBackend $calDavBackend,
		ICSExportPlugin $icsExportPlugin,
		IConfig $config,
		LoggerInterface $logger,
		IL10N $l10n
	) {
		$this->calendarManager = $calendarManager;
		$this->calDavBackend = $calDavBackend;
		$this->icsExportPlugin = $icsExportPlugin;
		$this->config = $config;
		$this->logger = $logger;
		$this->l10n = $l10n;

		$root = new RootCollection();
		$this->sabreDavServer = new SabreDavServer(new CachingTree($root));
		$this->sabreDavServer->addPlugin(new CalDAVPlugin());
		$this->sabreDavServer->addPlugin(new ExceptionLoggerPlugin(Application::APP_ID, \OC::$server->getLogger()));
	}

	// TODO exract logic into this helper function
	protected function getCalendarExports(): array {
		return ['name' => '', 'data' => ''];
	}

	// TODO drop all unnecessary code for testing purposes and integrate with user_migration app when API is ready
	public function export(IUser $user, OutputInterface $output): void {
		$userId = $user->getUID();
		$principalUri = CalendarMigrator::USERS_URI_ROOT . $userId;

		/** @var array<int, array{name: string, data: string}> $calendars */
		$calendars = array_values(array_filter(array_map(
			/** @return array{name: string, data: string} */
			function (ICalendar $calendar) use ($userId, $output) {
				$calendarId = $calendar->getKey();
				$calendarInfo = $this->calDavBackend->getCalendarById($calendarId);

				if (!empty($calendarInfo)) {
					$uri = $calendarInfo['uri'];
					$path = Plugin::CALENDAR_ROOT . "/$userId/$uri";

					// NOTE implementation below based on \Sabre\CalDAV\ICSExportPlugin::httpGet()

					$properties = $this->sabreDavServer->getProperties($path, [
						'{DAV:}resourcetype',
						'{DAV:}displayname',
						'{http://sabredav.org/ns}sync-token',
						'{DAV:}sync-token',
						'{http://apple.com/ns/ical/}calendar-color',
					]);

					// Filter out deleted calendars
					if (!isset($properties['{DAV:}resourcetype']) || !$properties['{DAV:}resourcetype']->is('{' . Plugin::NS_CALDAV . '}calendar')) {
						return;
					}

					if (!isset($properties['{http://nextcloud.com/ns}refresh-interval'])) {
						$value = $this->config->getAppValue('dav', 'defaultRefreshIntervalExportedCalendars', ICSExportPlugin::DEFAULT_REFRESH_INTERVAL);
						$properties['{http://nextcloud.com/ns}refresh-interval'] = $value;
					}

					// NOTE implementation below based on \Sabre\CalDAV\ICSExportPlugin::generateResponse()

					$calDataProp = '{' . Plugin::NS_CALDAV . '}calendar-data';
					$calendarNode = $this->sabreDavServer->tree->getNodeForPath($path);
					$nodes = $this->sabreDavServer->getPropertiesForPath($path, [$calDataProp], 1);

					$blobs = [];
					foreach ($nodes as $node) {
						if (isset($node[200][$calDataProp])) {
							$blobs[$node['href']] = $node[200][$calDataProp];
						}
					}
					unset($nodes);

					$mergedCalendar = $this->icsExportPlugin->mergeObjects(
						$properties,
						$blobs,
					);

					return [
						'name' => $calendarNode->getName(),
						'data' => $mergedCalendar->serialize(),
					];
				}

				$output->writeln("<error>Calendar $calendarId does not exist</error>");
				return 1;
			},
			$this->calendarManager->getCalendarsForPrincipal($principalUri),
		)));

		foreach ($calendars as ['name' => $name, 'data' => $data]) {
			// Set filename to sanitized calendar name appended with the date
			$filename = preg_replace('/[^a-zA-Z0-9-_ ]/um', '', $name) . '-' . date('Y-m-d') . CalendarMigrator::FILENAME_EXT;

			$this->writeExport(
				$user,
				$data,
				// TESTING directory does not automatically get created so just write to user directory, this will be put in a zip with all other data anyways
				// "/$userId/export/$appId",
				"/$userId",
				$filename,
				$output,
			);
		}
	}

	/**
	 * @param string $destDir relative path from the root of the user's calendar export folder
	 * @throws Exception
	 */
	protected function writeExport(IUser $user, string $data, string $destDir, string $filename, OutputInterface $output): void {
		$userId = $user->getUID();

		// setup filesystem
		// Requesting the user folder will set it up if the user hasn't logged in before
		\OC::$server->getUserFolder($userId);
		Filesystem::initMountPoints($userId);

		$view = new View();

		if ($view->file_put_contents("$destDir/$filename", $data) === false) {
			throw new Exception('Could not export calendar');
		}

		$output->writeln("✅ Exported calendar of user $userId into $destDir/$filename");
	}

	/**
	 * @throws Exception
	 */
	// TODO finish import calendar functionality
	public function import(IUser $user, string $srcDir, string $filename, OutputInterface $output): void {
		$userId = $user->getUID();
		$principalUri = CalendarMigrator::USERS_URI_ROOT . $userId;

		try {
			$vCalendar = VObjectReader::read(
				fopen("$srcDir/$filename", 'r')
			);
		} catch (FilesystemException $e) {
			throw new FilesystemException('Invalid path');
		}

		$problems = $vCalendar->validate();
		if (empty($problems)) {
			//  Implementation below based on https://github.com/nextcloud/cdav-library/blob/9b67034837fad9e8f764d0152211d46565bf01f2/src/models/calendarHome.js#L151

			$existingCalendarUris = array_map(
				fn (ICalendar $calendar) => $calendar->getUri(),
				$this->calendarManager->getCalendarsForPrincipal($principalUri),
			);

			// Parse initial calendar uri from filename
			$initialCalendarUri = $calendarUri = explode('-', $filename, 2)[0];

			$acc = 1;
			while (in_array($calendarUri, $existingCalendarUris, true)) {
				$calendarUri = $initialCalendarUri . "-$acc";
				++$acc;
			}

			// Create calendar
			$calendarId = $this->calDavBackend->createCalendar($principalUri, $calendarUri, [
				'{DAV:}displayname' => (string)$vCalendar->{'X-WR-CALNAME'},
				'{http://apple.com/ns/ical/}calendar-color' => (string)$vCalendar->{'X-APPLE-CALENDAR-COLOR'},
				'{http://owncloud.org/ns}calendar-enabled' => '1',
				'components' => 'VEVENT',
			]);

			$duplicateEvents = 0;

			// TODO parse result should be 3 components, ref: https://github.com/nextcloud/calendar-js/blob/72f97b58108c6fcb72111f7fe6bdd1993c8dbda1/src/parsers/icalendarParser.js#L187
			// Add events to created calendar
			/** @var Component $vObject */
			foreach ($vCalendar->VEVENT as $vObject) {
				// $eventData = $vObject->serialize();

				$lines = [
					"BEGIN:VCALENDAR\n",
					"BEGIN:VEVENT\n",
					...array_map(fn (Property $property) => $property->serialize(), $vObject->children()),
					"END:VEVENT\n",
					"END:VCALENDAR",
				];
				$eventData = implode('', $lines);

				// TODO get calendarData
				// ref: https://github.com/nextcloud/calendar-js/blob/72f97b58108c6fcb72111f7fe6bdd1993c8dbda1/src/components/calendarComponent.js#L104
				// ref: https://github.com/mozilla-comm/ical.js/blob/ba8e2522ffd30ffbe65197a96a487689d6e6e9a1/lib/ical/stringify.js#L60
				try {
					$this->calDavBackend->createCalendarObject(
						$calendarId,
						UUIDUtil::getUUID() . '.ics',
						$eventData,
						CalDavBackend::CALENDAR_TYPE_CALENDAR,
					);
				} catch (BadRequest $e) {
					++$duplicateEvents;
				}
			}

			$vCalendar->destroy();

			$output->writeln("✅ Imported calendar \"$filename\" to account of $userId" . ($duplicateEvents ? ", skipped $duplicateEvents duplicate events" : ''));
			return;
		}
		throw new Exception('Could not import calendar');
	}
}
