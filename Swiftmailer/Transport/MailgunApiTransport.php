<?php

namespace MauticPlugin\MauticMailgunMailerBundle\Swiftmailer\Transport;

use GuzzleHttp\Client;
use Mautic\EmailBundle\Model\TransportCallback;
use Mautic\EmailBundle\Swiftmailer\Transport\AbstractTokenArrayTransport;
use Mautic\EmailBundle\Swiftmailer\Transport\CallbackTransportInterface;
use Mautic\LeadBundle\Entity\DoNotContact;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Translation\TranslatorInterface;

class MailgunApiTransport extends AbstractTokenArrayTransport implements \Swift_Transport, CallbackTransportInterface
{
    private $host = 'api.%region_dot%mailgun.net';

    /**
     * @var int
     */
    private $maxBatchLimit;
    /**
     * @var int|null
     */
    private $batchRecipientCount;
    /**
     * @var Client
     */
    private $client;
    /**
     * @var string
     */
    private $apiKey;
    /**
     * @var string
     */
    private $domain;
    /**
     * @var string
     */
    private $region;
    /**
     * @var TranslatorInterface
     */
    private $translator;
    /**
     * @var TransportCallback
     */
    private $transportCallback;
    /**
     * @var null
     */
    private $webhookSigningKey;

    public function __construct(TransportCallback $transportCallback, Client $client, TranslatorInterface $translator, int $maxBatchLimit, ?int $batchRecipientCount, $webhookSigningKey = '', LoggerInterface $logger)
    {
        $this->transportCallback   = $transportCallback;
        $this->client              = $client;
        $this->translator          = $translator;
        $this->maxBatchLimit       = $maxBatchLimit;
        $this->batchRecipientCount = $batchRecipientCount ?: 0;
        $this->webhookSigningKey   = $webhookSigningKey;
        $this->logger              = $logger;
    }

    public function setApiKey(?string $apiKey): void
    {
        $this->apiKey = $apiKey;
    }

    public function getApiKey()
    {
        return $this->apiKey;
    }

    public function setDomain(?string $domain): void
    {
        $this->domain = $domain;
    }

    public function getDomain()
    {
        return $this->domain;
    }

    public function setRegion(?string $region): void
    {
        $this->region = $region;
    }

    public function getRegion()
    {
        return $this->region;
    }

    public function start(): void
    {
        if (empty($this->apiKey)) {
            $this->throwException($this->translator->trans('mautic.email.api_key_required', [], 'validators'));
        }

        $this->started = true;
    }

    /**
     * @param null $failedRecipients
     *
     * @return int
     *
     * @throws \Exception
     */
    public function send(\Swift_Mime_SimpleMessage $message, &$failedRecipients = null)
    {
        $count            = 0;
        $failedRecipients = (array) $failedRecipients;

        if ($evt = $this->getDispatcher()->createSendEvent($this, $message)) {
            $this->getDispatcher()->dispatchEvent($evt, 'beforeSendPerformed');
            if ($evt->bubbleCancelled()) {
                return 0;
            }
        }

        try {
            $count = $this->getBatchRecipientCount($message);

            $preparedMessage = $this->getMessage($message);

            $payload               = $this->getPayload($preparedMessage);
            $payload['v:CUSTOMID'] = null;
            if (isset($preparedMessage['headers']['TOTTGROUPID'])) {
                $payload['v:CUSTOMID'] = (int) $preparedMessage['headers']['TOTTGROUPID'];
            }

            $endpoint = sprintf('%s/v3/%s/messages', $this->getEndpoint(), urlencode($this->domain));
            $response = $this->client->post(
                'https://'.$endpoint,
                [
                    'auth'        => ['api', $this->apiKey, 'basic'],
                    'headers'     => $preparedMessage['headers'],
                    'form_params' => $payload,
                ]
            );

            if (Response::HTTP_OK !== $response->getStatusCode()) {
                if ('application/json' === $response->getHeaders(false)['content-type'][0]) {
                    $result = $response->toArray(false);
                    throw new \Swift_TransportException('Unable to send an email: '.$result['message'].sprintf(' (code %d).', $response->getStatusCode()), $response);
                }

                throw new \Swift_TransportException('Unable to send an email: '.$response->getContent(false).sprintf(' (code %d).', $response->getStatusCode()), $response);
            }

            if ($evt) {
                $evt->setResult(\Swift_Events_SendEvent::RESULT_SUCCESS);
                $evt->setFailedRecipients($failedRecipients);
                $this->getDispatcher()->dispatchEvent($evt, 'sendPerformed');
            }

            return $count;
        } catch (\Exception $e) {
            $this->triggerSendError($evt, $failedRecipients);
            $message->generateId();
            $this->throwException($e->getMessage());
        }

        return $count;
    }

    /**
     * Return the max number of to addresses allowed per batch.  If there is no limit, return 0.
     *
     * @see https://help.mailgun.com/hc/en-us/articles/203068914-What-Are-the-Differences-Between-the-Free-and-Flex-Plans-
     *      there is limit depending on your account, and you can change it in configuration for this plugin
     *      Free plan requires 300 messages per day
     */
    public function getMaxBatchLimit(): int
    {
        return $this->maxBatchLimit;
    }

    /**
     * Get the count for the max number of recipients per batch.
     *
     * @see https://help.mailgun.com/hc/en-us/articles/203068914-What-Are-the-Differences-Between-the-Free-and-Flex-Plans-
     *      5 Authorized Recipients for free plan and no limit for Flex Plan
     *
     * @param int    $toBeAdded Number of emails about to be added
     * @param string $type      Type of emails being added (to, cc, bcc)
     */
    public function getBatchRecipientCount(\Swift_Message $message, $toBeAdded = 1, $type = 'to'): int
    {
        $toCount  = is_array($message->getTo()) ? count($message->getTo()) : 0;
        $ccCount  = is_array($message->getCc()) ? count($message->getCc()) : 0;
        $bccCount = is_array($message->getBcc()) ? count($message->getBcc()) : 0;

        return null === $this->batchRecipientCount ? $this->batchRecipientCount : $toCount + $ccCount + $bccCount + $toBeAdded;
    }

    /**
     * Returns a "transport" string to match the URL path /mailer/{transport}/callback.
     */
    public function getCallbackPath(): string
    {
        return 'mailgun_api';
    }

    /**
     * Handle response.
     *
     * @preplaced
     *
     * @return mixed
     */
    public function processCallbackRequest(Request $request)
    {
        $postData = json_decode($request->getContent(), true);

        if (is_array($postData) && isset($postData['event-data'])) {
            // Mailgun API callback
            $events = [
                $postData['event-data'],
            ];
        } else {
            // response must be an array
            return null;
        }

        foreach ($events as $event) {
            if (!in_array($event['event'], ['bounce', 'rejected', 'complained', 'unsubscribed', 'permanent_fail', 'failed'])) {
                continue;
            }
            $reason = $event['event'];
            if ('bounce' === $event['event'] || 'rejected' === $event['event'] || 'permanent_fail' === $event['event'] || 'failed' === $event['event']) {
                if (!empty($event['delivery-status']['message'])) {
                    $reason = $event['delivery-status']['message'];
                } elseif (!empty($event['delivery-status']['description'])) {
                    $reason = $event['delivery-status']['description'];
                }
                $type = DoNotContact::BOUNCED;
            } elseif ('complained' === $event['event']) {
                if (isset($event['delivery-status']['message'])) {
                    $reason = $event['delivery-status']['message'];
                }
                $type = DoNotContact::UNSUBSCRIBED;
            } elseif ('unsubscribed' === $event['event']) {
                $reason = 'User unsubscribed';
                $type   = DoNotContact::UNSUBSCRIBED;
            } else {
                continue;
            }

            $channelId = null;
            if (isset($event['user-variables']['CUSTOMID'])) {
                $event['CustomID'] = $event['user-variables']['CUSTOMID'];

                // Make sure channel ID is always set, so data on graph is displayed correctly.
                $channelId = (int) $event['CustomID'];
            }

            if (isset($event['CustomID']) && '' !== $event['CustomID'] && false !== strpos($event['CustomID'], '-', 0)) {
                $fistDashPos = strpos($event['CustomID'], '-', 0);
                $leadIdHash  = substr($event['CustomID'], 0, $fistDashPos);
                $leadEmail   = substr($event['CustomID'], $fistDashPos + 1, strlen($event['CustomID']));
                if ($event['recipient'] == $leadEmail) {
                    $this->transportCallback->addFailureByHashId($leadIdHash, $reason, $type);
                }
            } else {
                $this->transportCallback->addFailureByAddress($event['recipient'], $reason, $type, $channelId);
            }
        }
    }

    /**
     * @param array $failedRecipients
     */
    private function triggerSendError(\Swift_Events_SendEvent $evt, &$failedRecipients): void
    {
        $failedRecipients = array_merge(
            $failedRecipients,
            array_keys((array) $this->message->getTo()),
            array_keys((array) $this->message->getCc()),
            array_keys((array) $this->message->getBcc())
        );

        if ($evt) {
            $evt->setResult(\Swift_Events_SendEvent::RESULT_FAILED);
            $evt->setFailedRecipients($failedRecipients);
            $this->getDispatcher()->dispatchEvent($evt, 'sendPerformed');
        }
    }

    private function getEndpoint(): string
    {
        return str_replace('%region_dot%', 'us' !== ($this->region ?: 'us') ? $this->region.'.' : '', $this->host);
    }

    private function getMessage($message): array
    {
        $this->message = $message;
        $metadata      = $this->getMetadata();

        $mauticTokens = $tokenReplace = $mailgunTokens = [];
        if (!empty($metadata)) {
            $metadataSet  = reset($metadata);
            $tokens       = (!empty($metadataSet['tokens'])) ? $metadataSet['tokens'] : [];
            $mauticTokens = array_keys($tokens);
            foreach ($tokens as $search => $token) {
                $tokenKey               = preg_replace('/[^\da-z]/i', '_', trim($search, '{}'));
                $tokenReplace[$search]  = '%recipient.'.$tokenKey.'%';
                $mailgunTokens[$search] = $tokenKey;
            }
        }

        $messageArray = $this->messageToArray($mauticTokens, $tokenReplace, true);

        $messageArray['recipient-variables'] = [];
        $messageArray['to']                  = [];
        foreach ($metadata as $recipient => $mailData) {
            $messageArray['to'][]                            = $recipient;
            $messageArray['recipient-variables'][$recipient] = [];
            foreach ($mailData['tokens'] as $token => $tokenData) {
                $messageArray['recipient-variables'][$recipient][$mailgunTokens[$token]] = $tokenData;
            }
        }

        if (empty($messageArray['to'])) {
            $messageArray['to'] = array_keys($messageArray['recipients']['to']);
        }

        return $messageArray;
    }

    private function getPayload(array $message): array
    {
        $payload = [
            'from'    => sprintf('%s <%s>', $message['from']['name'], $message['from']['email']),
            'to'      => $message['to'],
            'subject' => $message['subject'],
            'html'    => $message['html'],
            'text'    => $message['text'],
        ];
        if (count($message['recipient-variables'])) {
            $payload['recipient-variables'] = json_encode($message['recipient-variables']);
        }
        $this->logger->notice('recipient-variables');
        $this->logger->notice(json_encode($message['recipient-variables']));

        if (!empty($message['recipients']['cc'])) {
            $payload['cc'] = $message['recipients']['cc'];
        }

        if (!empty($message['recipients']['bcc'])) {
            $payload['bcc'] = $message['recipients']['bcc'];
        }

        return $payload;
    }

    public function verifyCallback(string $token, string $timestamp, string $signature): bool
    {
        // check if the timestamp is fresh
        if (\abs(\time() - $timestamp) > 15) {
            return false;
        }

        // returns true if signature is valid
        return \hash_equals(\hash_hmac('sha256', $timestamp.$token, $this->webhookSigningKey), $signature);
    }
}
