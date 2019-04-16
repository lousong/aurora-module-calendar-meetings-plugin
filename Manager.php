<?php
/**
 * This code is licensed under Afterlogic Software License.
 * For full statements of the license see LICENSE file.
 */

namespace Aurora\Modules\CalendarMeetingsPlugin;

/**
 * @license https://afterlogic.com/products/common-licensing Afterlogic Software License
 * @copyright Copyright (c) 2019, Afterlogic Corp.
 *
 * @package CalendarMeetingsPlugin
 * @subpackage Managers
 */
class Manager extends \Aurora\Modules\Calendar\Manager
{


	/**
	 * Processing response to event invitation. [Aurora only.](http://dev.afterlogic.com/aurora)
	 *
	 * @param string $sUserPublicId
	 * @param string $sCalendarId Calendar ID
	 * @param string $sEventId Event ID
	 * @param string $sAttendee Attendee identified by email address
	 * @param string $sAction Appointment actions. Accepted values:
	 *		- "ACCEPTED"
	 *		- "DECLINED"
	 *		- "TENTATIVE"
	 *
	 * @return bool
	 */
	public function updateAppointment($sUserPublicId, $sCalendarId, $sEventId, $sAttendee, $sAction)
	{
		$oResult = null;

		$aData = $this->oStorage->getEvent($sUserPublicId, $sCalendarId, $sEventId);
		if ($aData !== false)
		{
			$oVCal = $aData['vcal'];
			$oVCal->METHOD = 'REQUEST';
			return $this->appointmentAction($sUserPublicId, $sAttendee, $sAction, $sCalendarId, $oVCal->serialize());
		}

		return $oResult;
	}

	/**
	 * Allows for responding to event invitation (accept / decline / tentative). [Aurora only.](http://dev.afterlogic.com/aurora)
	 *
	 * @param int|string $sUserPublicId Account object
	 * @param string $sAttendee Attendee identified by email address
	 * @param string $sAction Appointment actions. Accepted values:
	 *		- "ACCEPTED"
	 *		- "DECLINED"
	 *		- "TENTATIVE"
	 * @param string $sCalendarId Calendar ID
	 * @param string $sData ICS data of the response
	 * @param bool $bExternal If **true**, it is assumed attendee is external to the system
	 *
	 * @return bool
	 */
	public function appointmentAction($sUserPublicId, $sAttendee, $sAction, $sCalendarId, $sData, $bExternal = false)
	{
		$oUser = null;
		$oAttendeeUser = null;
		$oDefaultUser = null;
		$bDefaultAccountAsEmail = false;
		$bIsDefaultAccount = false;

		if (isset($sUserPublicId))
		{
			$bDefaultAccountAsEmail = false;
			$oUser = \Aurora\System\Api::GetModuleDecorator('Core')->GetUserByPublicId($sUserPublicId);
			$oDefaultUser = $oUser;
		}
		else
		{
			$oAttendeeUser = \Aurora\System\Api::GetModuleDecorator('Core')->GetUserByPublicId($sAttendee);
			if ($oAttendeeUser instanceof \Aurora\Modules\Core\Classes\User)
			{
				$bDefaultAccountAsEmail = false;
				$oDefaultUser = $oAttendeeUser;
			}
			else
			{
				$bDefaultAccountAsEmail = true;
			}
		}
		$oFromAccount = null;
		if ($oDefaultUser && $oDefaultUser->PublicId !== $sAttendee)
		{
			$oMailModule = \Aurora\System\Api::GetModule('Mail');
			if ($oMailModule)
			{
				$aAccounts = $oMailModule->getAccountsManager()->getUserAccounts($oDefaultUser->EntityId);
				if (is_array($aAccounts))
				{
					foreach ($aAccounts as $oAccount)
					{
						if ($oAccount instanceof \Aurora\Modules\Mail\Classes\Account && $oAccount->Email === $sAttendee)
						{
							$oFromAccount = $oAccount;
							break;
						}
					}
				}
			}
		}

		if (!$bDefaultAccountAsEmail && !$bIsDefaultAccount)
		{
			$oCalendar = $this->getDefaultCalendar($oDefaultUser->PublicId);
			if ($oCalendar)
			{
				$sCalendarId = $oCalendar->Id;
			}
		}

		$bResult = false;
		$sEventId = null;

		$sTo = $sSubject = $sBody = $sSummary = '';

		$oVCal = \Sabre\VObject\Reader::read($sData);
		if ($oVCal)
		{
			$sMethod = $sMethodOriginal = (string) $oVCal->METHOD;
			$aVEvents = $oVCal->getBaseComponents('VEVENT');

			if (isset($aVEvents) && count($aVEvents) > 0)
			{
				$oVEvent = $aVEvents[0];
				$sEventId = (string)$oVEvent->UID;
				if (isset($oVEvent->SUMMARY))
				{
					$sSummary = (string)$oVEvent->SUMMARY;
				}
				if (isset($oVEvent->ORGANIZER))
				{
					$sTo = str_replace('mailto:', '', strtolower((string)$oVEvent->ORGANIZER));
				}
				if (strtoupper($sMethod) === 'REQUEST')
				{
					$sMethod = 'REPLY';
					$sSubject = $sSummary;

//						unset($oVEvent->ATTENDEE);
					$sPartstat = strtoupper($sAction);
					switch ($sPartstat)
					{
						case 'ACCEPTED':
							$sSubject = 'Accepted: '. $sSubject;
							break;
						case 'DECLINED':
							$sSubject = 'Declined: '. $sSubject;
							break;
						case 'TENTATIVE':
							$sSubject = 'Tentative: '. $sSubject;
							break;
					}

					$sCN = '';
					if (isset($oDefaultUser) && $sAttendee ===  $oDefaultUser->PublicId)
					{
						if (!empty($oDefaultUser->Name))
						{
							$sCN = $oDefaultUser->Name;
						}
						else
						{
							$sCN = $sAttendee;
						}
					}

					foreach($oVEvent->ATTENDEE as &$oAttendee)
					{
						$sEmail = str_replace('mailto:', '', strtolower((string)$oAttendee));
						if (strtolower($sEmail) === strtolower($sAttendee))
						{
							$oAttendee['CN'] = $sCN;
							$oAttendee['PARTSTAT'] = $sPartstat;
							$oAttendee['RESPONDED-AT'] = gmdate("Ymd\THis\Z");
						}
					}

/*
					$oVEvent->add('ATTENDEE', 'mailto:'.$sAttendee, array(
						'CN' => $sCN,
						'PARTSTAT' => $sPartstat,
						'RESPONDED-AT' => gmdate("Ymd\THis\Z")
					));
*/
				}

				$oVCal->METHOD = $sMethod;
				$oVEvent->{'LAST-MODIFIED'} = new \DateTime('now', new \DateTimeZone('UTC'));

				$sBody = $oVCal->serialize();

				if ($sCalendarId !== false && $bExternal === false && !$bDefaultAccountAsEmail)
				{
					unset($oVCal->METHOD);
					if (isset($oDefaultUser))
					{
						if ($sUserPublicId === $sAttendee && strtoupper($sAction) == 'DECLINED' || strtoupper($sMethod) == 'CANCEL')
						{
							$this->deleteEvent($oDefaultUser->PublicId, $sCalendarId, $sEventId);
						}
						else
						{
							$this->oStorage->updateEventRaw($oDefaultUser->PublicId, $sCalendarId, $sEventId, $oVCal->serialize());
						}
					}
				}

				if (strtoupper($sMethodOriginal) == 'REQUEST'/* && (strtoupper($sAction) !== 'DECLINED')*/)
				{
					if (!empty($sTo) && !empty($sBody) && isset($oDefaultUser) && $oDefaultUser instanceof \Aurora\Modules\Core\Classes\User &&
						$oDefaultUser->PublicId !== $sTo//don't sending message to user from himself
					)
					{
						$bResult = \Aurora\Modules\CalendarMeetingsPlugin\Classes\Helper::sendAppointmentMessage($oDefaultUser->PublicId, $sTo, $sSubject, $sBody, $sMethod, '', $oFromAccount);
					}
				}
				else
				{
					$bResult = true;
				}
			}
		}

		if (!$bResult)
		{
			\Aurora\System\Api::Log('Ics Appointment Action FALSE result!', \Aurora\System\Enums\LogLevel::Error);
			if ($sUserPublicId)
			{
				\Aurora\System\Api::Log('Email: ' . $oDefaultUser->PublicId . ', Action: '. $sAction.', Data:', \Aurora\System\Enums\LogLevel::Error);
			}
			\Aurora\System\Api::Log($sData, \Aurora\System\Enums\LogLevel::Error);
		}
		else
		{
			$bResult = $sEventId;
		}

		return $bResult;
	}
}






