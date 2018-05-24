<?php
/**
 * This code is licensed under AfterLogic Software License.
 * For full statements of the license see LICENSE file.
 */

namespace Aurora\Modules\CalendarMeetingsPlugin\Classes;

/**
 * @license https://afterlogic.com/products/common-licensing AfterLogic Software License
 * @copyright Copyright (c) 2018, Afterlogic Corp.
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
	public static function sendAppointmentMessage($sUserPublicId, $sTo, $sSubject, $sBody, $sMethod, $sHtmlBody='')
	{
		$oMessage = self::buildAppointmentMessage($sUserPublicId, $sTo, $sSubject, $sBody, $sMethod, $sHtmlBody);
		$oUser = \Aurora\System\Api::GetModuleDecorator('Core')->GetUserByPublicId($sUserPublicId);
		if ($oUser instanceof \Aurora\Modules\Core\Classes\User)
		{
			$oAccount = \Aurora\System\Api::GetModule('Mail')->oApiAccountsManager->getAccountByEmail($oUser->PublicId);
			if ($oMessage && $oAccount instanceof \Aurora\Modules\Mail\Classes\Account)
			{
				try
				{
					\Aurora\System\Api::Log('IcsAppointmentActionSendOriginalMailMessage');
					return \Aurora\System\Api::GetModule('Mail')->oApiMailManager->sendMessage($oAccount, $oMessage);
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
	public static function buildAppointmentMessage($sUserPublicId, $sTo, $sSubject, $sBody, $sMethod = null, $sHtmlBody = '')
	{
		$oMessage = null;
		$oUser = \Aurora\System\Api::GetModuleDecorator('Core')->GetUserByPublicId($sUserPublicId);
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
				->SetFrom(\MailSo\Mime\Email::NewInstance($oUser->PublicId))
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
		$oCalendarModule = \Aurora\System\Api::GetModule('Calendar');
		if ($oCalendarModule instanceof \Aurora\System\Module\AbstractModule)
		{
			$sHtml = file_get_contents($oCalendarModule->GetPath().'/templates/CalendarEventInvite.html');
			$sHtml = strtr($sHtml, array(
				'{{INVITE/LOCATION}}'	=> \Aurora\System\Api::I18N('INVITE/LOCATION'),
				'{{INVITE/WHEN}}'		=> \Aurora\System\Api::I18N('INVITE/WHEN'),
				'{{INVITE/DESCRIPTION}}'=> \Aurora\System\Api::I18N('INVITE/DESCRIPTION'),
				'{{INVITE/INFORMATION}}'=> \Aurora\System\Api::I18N('INVITE/INFORMATION', array('Email' => $sAttendee)),
				'{{INVITE/ACCEPT}}'		=> \Aurora\System\Api::I18N('INVITE/ACCEPT'),
				'{{INVITE/TENTATIVE}}'	=> \Aurora\System\Api::I18N('INVITE/TENTATIVE'),
				'{{INVITE/DECLINE}}'	=> \Aurora\System\Api::I18N('INVITE/DECLINE'),
				'{{Calendar}}'			=> $sCalendarName.' '.$sAccountEmail,
				'{{Location}}'			=> $oEvent->Location,
				'{{Start}}'				=> $sStartDate,
				'{{Description}}'		=> $oEvent->Description,
				'{{HrefAccept}}'		=> $sHref.$sEncodedValueAccept,
				'{{HrefTentative}}'		=> $sHref.$sEncodedValueTentative,
				'{{HrefDecline}}'		=> $sHref.$sEncodedValueDecline
			));
		}
		
		return $sHtml;
	}	
}
