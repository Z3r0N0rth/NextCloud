<?php
/**
 * @copyright Copyright (c) 2016, Roeland Jago Douma <roeland@famdouma.nl>
 * @copyright Copyright (c) 2016, Joas Schilling <coding@schilljs.com>
 *
 * @author Joas Schilling <coding@schilljs.com>
 * @author Roeland Jago Douma <roeland@famdouma.nl>
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
namespace OCA\DAV\CalDAV\Schedule;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\DAV\CalDAV\CalendarHome;
use Sabre\DAV\INode;
use Sabre\DAV\IProperties;
use Sabre\DAV\PropFind;
use Sabre\DAV\Server;
use Sabre\DAV\Xml\Property\LocalHref;
use Sabre\DAVACL\IPrincipal;

class Plugin extends \Sabre\CalDAV\Schedule\Plugin {

	/**
	 * Initializes the plugin
	 *
	 * @param Server $server
	 * @return void
	 */
	function initialize(Server $server) {
		parent::initialize($server);
		$server->on('propFind', [$this, 'propFindDefaultCalendarUrl'], 90);
	}

	/**
	 * This method handler is invoked during fetching of properties.
	 *
	 * We use this event to add calendar-auto-schedule-specific properties.
	 *
	 * @param PropFind $propFind
	 * @param INode $node
	 * @return void
	 */
	function propFind(PropFind $propFind, INode $node) {
		parent::propFind($propFind, $node);

		if ($node instanceof IPrincipal) {
			// overwrite Sabre/Dav's implementation
			$propFind->handle('{' . self::NS_CALDAV . '}calendar-user-type', function () use ($node) {
				if ($node instanceof IProperties) {
					$calendarUserType = '{' . self::NS_CALDAV . '}calendar-user-type';
					$props = $node->getProperties([$calendarUserType]);

					if (isset($props[$calendarUserType])) {
						return $props[$calendarUserType];
					}
				}

				return 'INDIVIDUAL';
			});
		}
	}

	/**
	 * Returns a list of addresses that are associated with a principal.
	 *
	 * @param string $principal
	 * @return array
	 */
	protected function getAddressesForPrincipal($principal) {
		$result = parent::getAddressesForPrincipal($principal);

		if ($result === null) {
			$result = [];
		}

		return $result;
	}

	/**
	 * Always use the personal calendar as target for scheduled events
	 *
	 * @param PropFind $propFind
	 * @param INode $node
	 * @return void
	 */
	function propFindDefaultCalendarUrl(PropFind $propFind, INode $node) {
		if ($node instanceof IPrincipal) {
			$propFind->handle('{' . self::NS_CALDAV . '}schedule-default-calendar-URL', function() use ($node) {
				/** @var \OCA\DAV\CalDAV\Plugin $caldavPlugin */
				$caldavPlugin = $this->server->getPlugin('caldav');
				$principalUrl = $node->getPrincipalUrl();

				$calendarHomePath = $caldavPlugin->getCalendarHomeForPrincipal($principalUrl);
				if (!$calendarHomePath) {
					return null;
				}

				if (strpos($principalUrl, 'principals/users') === 0) {
					$uri = CalDavBackend::PERSONAL_CALENDAR_URI;
					$displayname = CalDavBackend::PERSONAL_CALENDAR_NAME;
				} elseif (strpos($principalUrl, 'principals/calendar-resources') === 0 ||
						  strpos($principalUrl, 'principals/calendar-rooms') === 0) {
					$uri = CalDavBackend::RESOURCE_BOOKING_CALENDAR_URI;
					$displayname = CalDavBackend::RESOURCE_BOOKING_CALENDAR_NAME;
				} else {
					// How did we end up here?
					// TODO - throw exception or just ignore?
					return null;
				}

				/** @var CalendarHome $calendarHome */
				$calendarHome = $this->server->tree->getNodeForPath($calendarHomePath);
				if (!$calendarHome->childExists($uri)) {
					$calendarHome->getCalDAVBackend()->createCalendar($principalUrl, $uri, [
						'{DAV:}displayname' => $displayname,
					]);
				}

				$result = $this->server->getPropertiesForPath($calendarHomePath . '/' . $uri, [], 1);
				if (empty($result)) {
					return null;
				}

				return new LocalHref($result[0]['href']);
			});
		}
	}
}
