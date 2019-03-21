<?php
/**
 * This code is licensed under AfterLogic Software License.
 * For full statements of the license see LICENSE file.
 */

namespace Aurora\Modules\CalendarMeetingsPlugin\Classes;

/**
 * @license https://afterlogic.com/products/common-licensing AfterLogic Software License
 * @copyright Copyright (c) 2019, Afterlogic Corp.
 *
 * @internal
 * 
 * @package Calendar
 * @subpackage Classes
 */
class Helper
{
	/**
	 * @param string $sUserPublicId
	 * @param string $sTo
	 * @param string $sSubject
	 * @param string $sBody
	 * @param string $sMethod
	 * @param string $sHtmlBody Default value is empty string.
	 *
	 * @throws \Aurora\System\Exceptions\ApiException
	 *
	 * @return \MailSo\Mime\Message
	 */
	public static function sendAppointmentMessage($sUserPublicId, $sTo, $sSubject, $sBody, $sMethod, $sHtmlBody='', $oAccount = null)
	{
		$oMessage = self::buildAppointmentMessage($sUserPublicId, $sTo, $sSubject, $sBody, $sMethod, $sHtmlBody, $oAccount);
		$oUser = \Aurora\System\Api::GetModuleDecorator('Core')->GetUserByPublicId($sUserPublicId);
		if ($oUser instanceof \Aurora\Modules\Core\Classes\User)
		{
			$oAccount = $oAccount ? $oAccount : \Aurora\System\Api::GetModule('Mail')->getAccountsManager()->getAccountByEmail($oUser->PublicId);
			if ($oMessage && $oAccount instanceof \Aurora\Modules\Mail\Classes\Account)
			{
				try
				{
					\Aurora\System\Api::Log('IcsAppointmentActionSendOriginalMailMessage');
					return \Aurora\System\Api::GetModule('Mail')->getMailManager()->sendMessage($oAccount, $oMessage);
				}
				catch (\Aurora\System\Exceptions\ManagerException $oException)
				{
					$iCode = \Core\Notifications::CanNotSendMessage;
					switch ($oException->getCode())
					{
						case Errs::Mail_InvalidRecipients:
							$iCode = \Core\Notifications::InvalidRecipients;
							break;
					}

					throw new \Aurora\System\Exceptions\ApiException($iCode, $oException);
				}
			}
		}

		return false;
	}

	/**
	 * @param string $sUserPublicId
	 * @param string $sTo
	 * @param string $sSubject
	 * @param string $sBody
	 * @param string $sMethod Default value is **null**.
	 * @param string $sHtmlBody Default value is empty string.
	 *
	 * @return \MailSo\Mime\Message
	 */
	public static function buildAppointmentMessage($sUserPublicId, $sTo, $sSubject, $sBody, $sMethod = null, $sHtmlBody = '', $oAccount = null)
	{
		$oMessage = null;
		$oUser = \Aurora\System\Api::GetModuleDecorator('Core')->GetUserByPublicId($sUserPublicId);
		$sFrom = $oAccount ? $oAccount->Email : $oUser->PublicId;
		if ($oUser instanceof \Aurora\Modules\Core\Classes\User && !empty($sTo) && !empty($sBody))
		{
			$oMessage = \MailSo\Mime\Message::NewInstance();
			$oMessage->RegenerateMessageId();
			$oMessage->DoesNotCreateEmptyTextPart();

			$oMailModule = \Aurora\System\Api::GetModule('Mail'); 
			$sXMailer = $oMailModule ? $oMailModule->getConfig('XMailerValue', '') : '';
			if (0 < strlen($sXMailer))
			{
				$oMessage->SetXMailer($sXMailer);
			}

			$oMessage
				->SetFrom(\MailSo\Mime\Email::NewInstance($sFrom))
				->SetSubject($sSubject)
			;

			$oMessage->AddHtml($sHtmlBody);

			$oToEmails = \MailSo\Mime\EmailCollection::NewInstance($sTo);
			if ($oToEmails && $oToEmails->Count())
			{
				$oMessage->SetTo($oToEmails);
			}

			if ($sMethod)
			{
				$oMessage->SetCustomHeader('Method', $sMethod);
			}

			$oMessage->AddAlternative('text/calendar', \MailSo\Base\ResourceRegistry::CreateMemoryResourceFromString($sBody),
					\MailSo\Base\Enumerations\Encoding::_8_BIT, null === $sMethod ? array() : array('method' => $sMethod));
		}

		return $oMessage;
	}

	/**
	 * @param \Aurora\Modules\Calendar\Classes\Event $oEvent
	 * @param string $sAccountEmail
	 * @param string $sAttendee
	 * @param string $sCalendarName
	 * @param string $sStartDate
	 *
	 * @return string
	 */
	public static function createHtmlFromEvent($oEvent, $sAccountEmail, $sAttendee, $sCalendarName, $sStartDate)
	{
		$sHtml = '';
		$aValues = array(
			'attendee' => $sAttendee,
			'organizer' => $sAccountEmail,
			'calendarId' => $oEvent->IdCalendar,
			'eventId' => $oEvent->Id
		);

		$aValues['action'] = 'ACCEPTED';
		$sEncodedValueAccept = \Aurora\System\Api::EncodeKeyValues($aValues);
		$aValues['action'] = 'TENTATIVE';
		$sEncodedValueTentative = \Aurora\System\Api::EncodeKeyValues($aValues);
		$aValues['action'] = 'DECLINED';
		$sEncodedValueDecline = \Aurora\System\Api::EncodeKeyValues($aValues);

		$sHref = rtrim(\MailSo\Base\Http::SingletonInstance()->GetFullUrl(), '\\/ ').'/?invite=';
		$oCalendarMeetingsModule = \Aurora\System\Api::GetModule('CalendarMeetingsPlugin');
		if ($oCalendarMeetingsModule instanceof \Aurora\System\Module\AbstractModule)
		{
			$sHtml = file_get_contents($oCalendarMeetingsModule->GetPath().'/templates/CalendarEventInvite.html');
			$sHtml = strtr($sHtml, array(
				'{{INVITE/LOCATION}}'	=> $oCalendarMeetingsModule->i18N('LOCATION'),
				'{{INVITE/WHEN}}'		=> $oCalendarMeetingsModule->I18N('WHEN'),
				'{{INVITE/DESCRIPTION}}'	=> $oCalendarMeetingsModule->i18N('DESCRIPTION'),
				'{{INVITE/INFORMATION}}'	=> $oCalendarMeetingsModule->i18N('INFORMATION', array('Email' => $sAttendee)),
				'{{INVITE/ACCEPT}}'		=> $oCalendarMeetingsModule->i18N('ACCEPT'),
				'{{INVITE/TENTATIVE}}'	=> $oCalendarMeetingsModule->i18N('TENTATIVE'),
				'{{INVITE/DECLINE}}'		=> $oCalendarMeetingsModule->i18N('DECLINE'),
				'{{Calendar}}'			=> $sCalendarName.' '.$sAccountEmail,
				'{{Location}}'			=> $oEvent->Location,
				'{{Start}}'				=> $sStartDate,
				'{{Description}}'			=> $oEvent->Description,
				'{{HrefAccept}}'			=> $sHref.$sEncodedValueAccept,
				'{{HrefTentative}}'		=> $sHref.$sEncodedValueTentative,
				'{{HrefDecline}}'			=> $sHref.$sEncodedValueDecline
			));
		}

		return $sHtml;
	}

	/**
	 * @param string $sUserPublicId
	 * @param string $sTo
	 * @param string $sSubject
	 * @param string $sHtmlBody
	 *
	 * @throws \Aurora\System\Exceptions\ApiException
	 *
	 * @return \MailSo\Mime\Message
	 */
	public static function sendSelfNotificationMessage($sUserPublicId, $sTo, $sSubject, $sHtmlBody)
	{
		$oMessage = self::buildSelfNotificationMessage($sUserPublicId, $sTo, $sSubject, $sHtmlBody);
		$oUser = \Aurora\System\Api::GetModuleDecorator('Core')->GetUserByPublicId($sUserPublicId);
		if ($oUser instanceof \Aurora\Modules\Core\Classes\User)
		{
			$oAccount = \Aurora\System\Api::GetModule('Mail')->getAccountsManager()->getAccountByEmail($oUser->PublicId);
			if ($oMessage && $oAccount instanceof \Aurora\Modules\Mail\Classes\Account)
			{
				try
				{
					\Aurora\System\Api::Log('IcsAppointmentActionSendSelfMailMessage');
					return \Aurora\System\Api::GetModule('Mail')->getMailManager()->sendMessage($oAccount, $oMessage);
				}
				catch (\Aurora\System\Exceptions\ManagerException $oException)
				{
					$iCode = \Core\Notifications::CanNotSendMessage;
					switch ($oException->getCode())
					{
						case Errs::Mail_InvalidRecipients:
							$iCode = \Core\Notifications::InvalidRecipients;
							break;
					}

					throw new \Aurora\System\Exceptions\ApiException($iCode, $oException);
				}
			}
		}

		return false;
	}

	/**
	 * @param string $sUserPublicId
	 * @param string $sTo
	 * @param string $sSubject
	 * @param string $sHtmlBody
	 *
	 * @return \MailSo\Mime\Message
	 */
	public static function buildSelfNotificationMessage($sUserPublicId, $sTo, $sSubject, $sHtmlBody)
	{
		$oMessage = null;
		$oUser = \Aurora\System\Api::GetModuleDecorator('Core')->GetUserByPublicId($sUserPublicId);
		if ($oUser instanceof \Aurora\Modules\Core\Classes\User && !empty($sTo) && !empty($sHtmlBody))
		{
			$oMessage = \MailSo\Mime\Message::NewInstance();
			$oMessage->RegenerateMessageId();
			$oMessage->DoesNotCreateEmptyTextPart();

			$oMailModule = \Aurora\System\Api::GetModule('Mail');
			$sXMailer = $oMailModule ? $oMailModule->getConfig('XMailerValue', '') : '';
			if (0 < strlen($sXMailer))
			{
				$oMessage->SetXMailer($sXMailer);
			}

			$oMessage
				->SetFrom(\MailSo\Mime\Email::NewInstance($oUser->PublicId))
				->SetSubject($sSubject)
			;

			$oMessage->AddHtml($sHtmlBody);

			$oToEmails = \MailSo\Mime\EmailCollection::NewInstance($sTo);
			if ($oToEmails && $oToEmails->Count())
			{
				$oMessage->SetTo($oToEmails);
			}
		}

		return $oMessage;
	}

	public static function createSelfNotificationSubject($sAction, $sEventName)
	{
		$sResult = self::getSelfNotificationActionName($sAction) . ': ' . $sEventName;

		return $sResult;
	}

	public static function createSelfNotificationHtmlBody($sAction, $aEvent, $sEmail, $sCalendarName, $sStartDate)
	{
		$sHtml = '';
		$sActionName = self::getSelfNotificationActionName($sAction);
		$oCalendarMeetingsModule = \Aurora\System\Api::GetModule('CalendarMeetingsPlugin');
		if ($oCalendarMeetingsModule instanceof \Aurora\System\Module\AbstractModule)
		{
			$sHtml = file_get_contents($oCalendarMeetingsModule->GetPath().'/templates/CalendarEventSelfNotification.html');
			$sHtml = strtr($sHtml, [
				'{{LOCATION}}'			=> $oCalendarMeetingsModule->i18N('LOCATION'),
				'{{WHEN}}'				=> $oCalendarMeetingsModule->I18N('WHEN'),
				'{{DESCRIPTION}}'		=> $oCalendarMeetingsModule->i18N('DESCRIPTION'),
				'{{INFORMATION}}'		=> $oCalendarMeetingsModule->i18N('INFORMATION', ['Email' => $sEmail]),
				'{{REACTION}}'			=> $oCalendarMeetingsModule->i18N('USER_REACTION'),
				'{{Calendar}}'			=> $sCalendarName.' '.$sEmail,
				'{{Location}}'			=> $aEvent['location'],
				'{{Start}}'				=> $sStartDate,
				'{{Description}}'			=> $aEvent['description'],
				'{{Reaction}}'			=> $sActionName
			]);
		}

		return $sHtml;
	}

	public static function getSelfNotificationActionName($sAction)
	{
		$sResult = '';
		$oCalendarMeetingsModule = \Aurora\System\Api::GetModule('CalendarMeetingsPlugin');
		switch ($sAction)
		{
			case 'ACCEPTED':
				$sResult = $oCalendarMeetingsModule->i18N('ACCEPT');
				break;
			case 'DECLINED':
				$sResult = $oCalendarMeetingsModule->i18N('DECLINE');
				break;
			case 'TENTATIVE':
				$sResult = $oCalendarMeetingsModule->i18N('TENTATIVE');
				break;
		}

		return $sResult;
	}
}
