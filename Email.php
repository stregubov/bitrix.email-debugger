<?php

use Bitrix\Main\Mail\Context;
use Bitrix\Main\Mail\EventMessageCompiler;
use Bitrix\Main\Mail\Internal\EventAttachmentTable;
use Bitrix\Main\Mail\Internal\EventTable;
use Bitrix\Main\Mail\StopException;
use Bitrix\Main\Mail\Internal as MailInternal;

class Email
{
    public static function executeEvent(int $id): ?array
    {
        $connection = \Bitrix\Main\Application::getConnection();
        $strSql = "
				SELECT ID, C_FIELDS, EVENT_NAME, MESSAGE_ID, LID,
					DATE_FORMAT(DATE_INSERT, '%d.%m.%Y %H:%i:%s') as DATE_INSERT,
					DUPLICATE, LANGUAGE_ID
				FROM b_event
				WHERE ID = $id";
        $rsMails = $connection->query($strSql);

        $arMail = $rsMails->fetch();
        if ($arMail) {
            $arCallableModificator = array();
            foreach (EventTable::getFetchModificatorsForFieldsField() as $callableModificator) {
                if (is_callable($callableModificator)) {
                    $arCallableModificator[] = $callableModificator;
                }
            }

            foreach ($arCallableModificator as $callableModificator) {
                $arMail['C_FIELDS'] = call_user_func_array($callableModificator, array($arMail['C_FIELDS']));
            }

            $arFiles = array();
            $fileListDb = EventAttachmentTable::getList(array(
                'select' => array('FILE_ID'),
                'filter' => array('=EVENT_ID' => $arMail["ID"])
            ));
            while ($file = $fileListDb->fetch()) {
                $arFiles[] = $file['FILE_ID'];
            }
            $arMail['FILE'] = $arFiles;

            if (!is_array($arMail['C_FIELDS'])) {
                $arMail['C_FIELDS'] = array();
            }
            try {
                return static::compileMailData($arMail);
            } catch (\Exception $e) {
                $application = \Bitrix\Main\Application::getInstance();
                $exceptionHandler = $application->getExceptionHandler();
                $exceptionHandler->writeToLog($e);
            }
        }
    }

    private static function compileMailData(array $arEvent): ?array
    {
        if(!isset($arEvent['FIELDS']) && isset($arEvent['C_FIELDS']))
            $arEvent['FIELDS'] = $arEvent['C_FIELDS'];

        if(!is_array($arEvent['FIELDS']))
            throw new \Bitrix\Main\ArgumentTypeException("FIELDS" );

        $trackRead = null;
        $trackClick = null;
        if(array_key_exists('TRACK_READ', $arEvent))
            $trackRead = $arEvent['TRACK_READ'];
        if(array_key_exists('TRACK_CLICK', $arEvent))
            $trackClick = $arEvent['TRACK_CLICK'];

        $arSites = explode(",", $arEvent["LID"]);
        if(empty($arSites))
        {
            return null;
        }

        $charset = false;
        $serverName = null;

        static $sites = array();
        $infoSite = reset($arSites);

        if(!isset($sites[$infoSite]))
        {
            $siteDb = \Bitrix\Main\SiteTable::getList(array(
                'select' => array('SERVER_NAME', 'CULTURE_CHARSET'=>'CULTURE.CHARSET'),
                'filter' => array('=LID' => $infoSite)
            ));
            $sites[$infoSite] = $siteDb->fetch();
        }

        if(is_array($sites[$infoSite]))
        {
            $charset = $sites[$infoSite]['CULTURE_CHARSET'];
            $serverName = $sites[$infoSite]['SERVER_NAME'];
        }

        if(!$charset)
        {
            return null;
        }

        $arEventMessageFilter = array();
        $MESSAGE_ID = intval($arEvent["MESSAGE_ID"]);
        if($MESSAGE_ID > 0)
        {
            $eventMessageDb = MailInternal\EventMessageTable::getById($MESSAGE_ID);
            if($eventMessageDb->Fetch())
            {
                $arEventMessageFilter['=ID'] = $MESSAGE_ID;
                $arEventMessageFilter['=ACTIVE'] = 'Y';
            }
        }
        if(empty($arEventMessageFilter))
        {
            $arEventMessageFilter = array(
                '=ACTIVE' => 'Y',
                '=EVENT_NAME' => $arEvent["EVENT_NAME"],
                '=EVENT_MESSAGE_SITE.SITE_ID' => $arSites,
            );

            if($arEvent["LANGUAGE_ID"] <> '')
            {
                $arEventMessageFilter[] = array(
                    "LOGIC" => "OR",
                    array("=LANGUAGE_ID" => $arEvent["LANGUAGE_ID"]),
                    array("=LANGUAGE_ID" => null),
                );
            }
        }

        $messageDb = MailInternal\EventMessageTable::getList(array(
            'select' => array('ID'),
            'filter' => $arEventMessageFilter,
            'group' => array('ID')
        ));

        $messages = [];
        while($arMessage = $messageDb->fetch())
        {
            $eventMessage = MailInternal\EventMessageTable::getRowById($arMessage['ID']);

            $eventMessage['FILES'] = array();
            $attachmentDb = MailInternal\EventMessageAttachmentTable::getList(array(
                'select' => array('FILE_ID'),
                'filter' => array('=EVENT_MESSAGE_ID' => $arMessage['ID']),
            ));
            while($arAttachmentDb = $attachmentDb->fetch())
            {
                $eventMessage['FILE'][] = $arAttachmentDb['FILE_ID'];
            }

            $context = new Context();
            $arFields = $arEvent['FIELDS'];

            $arMessageParams = array(
                'EVENT' => $arEvent,
                'FIELDS' => $arFields,
                'MESSAGE' => $eventMessage,
                'SITE' => $arSites,
                'CHARSET' => $charset,
            );
            $message = EventMessageCompiler::createInstance($arMessageParams);
            try
            {
                $message->compile();
            }
            catch(StopException $e)
            {
                continue;
            }

            $messages[] = array(
                'TO' => $message->getMailTo(),
                'SUBJECT' => $message->getMailSubject(),
                'BODY' => $message->getMailBody(),
                'HEADER' => $message->getMailHeaders(),
                'CHARSET' => $message->getMailCharset(),
                'CONTENT_TYPE' => $message->getMailContentType(),
                'MESSAGE_ID' => $message->getMailId(),
                'ATTACHMENT' => $message->getMailAttachment(),
                'TRACK_READ' => $trackRead,
                'TRACK_CLICK' => $trackClick,
                'LINK_PROTOCOL' => \Bitrix\Main\Config\Option::get("main", "mail_link_protocol", ''),
                'LINK_DOMAIN' => $serverName,
                'CONTEXT' => $context,
            );
        }


        return $messages;
    }
}
