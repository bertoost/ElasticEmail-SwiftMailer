<?php

namespace bertoost\ElasticEmail\SwiftMailer;

use ElasticEmail\Api\EmailsApi;
use ElasticEmail\Model\BodyContentType;
use ElasticEmail\Model\BodyPart;
use ElasticEmail\Model\EmailContent;
use ElasticEmail\Model\EmailTransactionalMessageData;
use ElasticEmail\Model\MessageAttachment;
use ElasticEmail\Model\TransactionalRecipient;
use Swift_Attachment;
use Swift_Events_EventDispatcher;
use Swift_Events_EventListener;
use Swift_Events_SendEvent;
use Swift_Mime_Headers_AbstractHeader;
use Swift_Mime_SimpleMessage;
use Swift_MimePart;
use Swift_Transport;
use Swift_TransportException;

class ElasticEmailTransport implements Swift_Transport
{
    /**
     * @var EmailsApi $client
     */
    private $client;

    /**
     * The event dispatcher from the plugin API.
     *
     * @var Swift_Events_EventDispatcher eventDispatcher
     */
    private $eventDispatcher;

    public function __construct(Swift_Events_EventDispatcher $eventDispatcher, EmailsApi $client)
    {
        $this->eventDispatcher = $eventDispatcher;
        $this->client = $client;
    }

    public function isStarted()
    {
        return true;
    }

    public function start()
    {
    }

    public function stop()
    {
    }

    public function ping()
    {
        return true;
    }

    /**
     * Register a plugin in the Transport.
     *
     * @param Swift_Events_EventListener $plugin
     */
    public function registerPlugin(Swift_Events_EventListener $plugin)
    {
        $this->eventDispatcher->bindEventListener($plugin);
    }

    /**
     * Send the given Message.
     *
     * @throws Swift_TransportException
     */
    public function send(Swift_Mime_SimpleMessage $message, &$failedRecipients = null): int
    {
        $failedRecipients = (array)$failedRecipients;

        if ($evt = $this->eventDispatcher->createSendEvent($this, $message)) {
            $this->eventDispatcher->dispatchEvent($evt, 'beforeSendPerformed');
            if ($evt->bubbleCancelled()) {
                return 0;
            }
        }

        if (null === $message->getHeaders()->get('To')) {
            throw new Swift_TransportException('Cannot send message without a recipient');
        }

        $messageData = $this->getPayload($message);
        $sent = count($messageData->getRecipients()->getTo());

        try {
            $response = $this->client->emailsTransactionalPost($messageData);
            $resultStatus = Swift_Events_SendEvent::RESULT_SUCCESS;
        } catch (\Exception $e) {
            $failedRecipients = $messageData->getRecipients()->getTo();
            $sent = 0;
            $resultStatus = Swift_Events_SendEvent::RESULT_FAILED;
        }

        if ($evt) {
            $evt->setResult($resultStatus);
            $evt->setFailedRecipients($failedRecipients);

            $this->eventDispatcher->dispatchEvent($evt, 'sendPerformed');
        }

        return $sent;
    }

    private function getPayload(Swift_Mime_SimpleMessage $email): EmailTransactionalMessageData
    {
        $from = [];
        foreach ($email->getFrom() as $fromEmail => $fromName) {
            $from[] = $this->formatAddress($fromEmail, $fromName);
        }

        $replyTo = [];
        if (null !== ($addresses = $email->getReplyTo())) {
            foreach ($addresses as $replyEmail => $replyName) {
                $replyTo[] = $this->formatAddress($replyEmail, $replyName);
            }
        }

        return new EmailTransactionalMessageData([
            'recipients' => $this->buildRecipients($email),
            'content' => new EmailContent([
                'body' => $this->buildBody($email),
                'from' => implode(', ', $from),
                'subject' => $email->getSubject(),
                'attachments' => $this->buildAttachments($email),
                'headers' => $this->buildHeaders($email),
                'reply_to' => !empty($replyTo) ? implode(', ', $replyTo) : null,
            ]),
        ]);
    }

    private function buildRecipients(Swift_Mime_SimpleMessage $email): TransactionalRecipient
    {
        $tos = [];
        foreach ($email->getTo() as $toEmail => $toName) {
            $tos[] = $this->formatAddress($toEmail, $toName);
        }

        $ccs = [];
        if (null !== ($cc = $email->getCc())) {
            foreach ($cc as $ccEmail => $ccName) {
                $ccs[] = $this->formatAddress($ccEmail, $ccName);
            }
        }

        $bccs = [];
        if (null !== ($bcc = $email->getBcc())) {
            foreach ($bcc as $bccEmail => $bccName) {
                $bccs[] = $this->formatAddress($bccEmail, $bccName);
            }
        }

        return new TransactionalRecipient([
            'to' => !empty($tos) ? $tos : null,
            'cc' => !empty($ccs) ? $ccs : null,
            'bcc' => !empty($bccs) ? $bccs : null,
        ]);
    }

    /**
     * @return BodyPart[]
     */
    private function buildBody(Swift_Mime_SimpleMessage $email): array
    {
        $bodyHtml = $bodyText = null;
        if ($email->getContentType() === 'text/plain') {
            $bodyText = $email->getBody();
        } else {
            $bodyHtml = $email->getBody();
        }

        foreach ($email->getChildren() as $child) {
            if ($child instanceof Swift_MimePart
                && in_array($child->getContentType(), ['text/plain', 'text/html'], true)
            ) {
                if ($child->getContentType() == 'text/html') {
                    $bodyHtml = $child->getBody();
                } elseif ($child->getContentType() == 'text/plain') {
                    $bodyText = $child->getBody();
                }
            }
        }

        $bodies = [];

        if (null !== $bodyHtml) {
            $bodies[] = new BodyPart([
                'content_type' => BodyContentType::HTML,
                'content' => $bodyHtml,
            ]);
        }

        if (null !== $bodyText) {
            $bodies[] = new BodyPart([
                'content_type' => BodyContentType::PLAIN_TEXT,
                'content' => $bodyText,
            ]);
        }

        return $bodies;
    }

    /**
     * @return MessageAttachment[]
     */
    private function buildAttachments(Swift_Mime_SimpleMessage $email): array
    {
        $list = [];

        foreach ($email->getChildren() as $child) {
            if ($child instanceof Swift_Attachment) {
                $list[] = new MessageAttachment([
                    'name' => $child->getFilename(),
                    'content_type' => $child->getContentType(),
                    'binary_content' => $child->getBody(),
                ]);
            }
        }

        return $list;
    }

    private function buildHeaders(Swift_Mime_SimpleMessage $email): array
    {
        $list = [];
        $headersToBypass = ['from', 'to', 'cc', 'bcc', 'subject', 'content-type'];

        /** @var Swift_Mime_Headers_AbstractHeader $header */
        foreach ($email->getHeaders()->getAll() as $name => $header) {
            if (\in_array(strtolower($name), $headersToBypass, true)) {
                continue;
            }

            $list[$header->getFieldName()] = $header->getFieldBody();
        }

        return $list;
    }

    private function formatAddress(string $email, string $name = null): string
    {
        if (!empty($name)) {
            return sprintf('%s <%s>', $name, $email);
        }

        return $email;
    }
}