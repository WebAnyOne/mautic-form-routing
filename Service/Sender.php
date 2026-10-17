<?php

namespace MauticPlugin\WebAnyOneFormRoutingBundle\Service;

final class Sender
{
    private $helper;
    private $recipients;
    public function __construct(\Mautic\EmailBundle\Helper\MailHelper $helper, Recipients $recipients)
    {
        $this->helper = $helper;
        $this->recipients = $recipients;
    }
    public function send(array $message, array $group, bool $reply) : bool
    {
        $group = $this->recipients->normalize($group, true);
        $mail = $this->helper->getMailer();
        try {
            // Internal notification: never associate the prospect or its marketing DNC state.
            $mail->setLead([], true);
            $mail->setTo(Recipients::addresses($group['to']));
            $mail->setCc(Recipients::addresses($group['cc']));
            $mail->setBcc(Recipients::addresses($group['bcc']));
            $mail->setSubject($message['subject']);
            $mail->setBody($message['body'], 'text/html', 'UTF-8', true);
            $mail->parsePlainText($message['body']);
            // PlainTextHelper decodes HTML entities; prevent native signature/brand substitution there too.
            $mail->setPlainText(str_replace(['{', '}'], ['(', ')'], $mail->getPlainText()));
            if ($reply && $message['reply'] && $this->recipients->valid($message['reply'])) {
                $mail->setReplyTo($message['reply']);
            }
            return (bool) $mail->send(false);
        } finally {
            $mail->reset();
        }
    }
}
