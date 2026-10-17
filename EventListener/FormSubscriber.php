<?php

namespace MauticPlugin\WebAnyOneFormRoutingBundle\EventListener;

use Mautic\FormBundle\FormEvents;
use Mautic\FormBundle\Event\FormBuilderEvent;
use Mautic\FormBundle\Event\SubmissionEvent;
use MauticPlugin\WebAnyOneFormRoutingBundle\Service\Catalogue;
use MauticPlugin\WebAnyOneFormRoutingBundle\Service\Router;
use MauticPlugin\WebAnyOneFormRoutingBundle\Service\Composer;
use MauticPlugin\WebAnyOneFormRoutingBundle\Service\Sender;
use MauticPlugin\WebAnyOneFormRoutingBundle\Form\Type\RoutingType;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Psr\Log\LoggerInterface;
final class FormSubscriber implements EventSubscriberInterface
{
    const ACTION = 'form.routing_results';
    private $catalogue;
    private $router;
    private $composer;
    private $sender;
    private $logger;
    public function __construct(Catalogue $catalogue, Router $router, Composer $composer, Sender $sender, LoggerInterface $logger)
    {
        $this->catalogue = $catalogue;
        $this->router = $router;
        $this->composer = $composer;
        $this->sender = $sender;
        $this->logger = $logger;
    }
    public static function getSubscribedEvents() : array
    {
        return [FormEvents::FORM_ON_BUILD => ['build', 0], FormEvents::ON_EXECUTE_SUBMIT_ACTION => ['execute', 0]];
    }
    public function build(FormBuilderEvent $event) : void
    {
        $legacy = defined('MAUTIC_VERSION') && version_compare(MAUTIC_VERSION, '5.0', '<');
        $event->addSubmitAction(self::ACTION, ['group' => 'mautic.email.actions', 'label' => 'webanyone.formrouting.title', 'description' => 'webanyone.formrouting.description', 'formType' => RoutingType::class, 'formTheme' => $legacy ? 'WebAnyOneFormRoutingBundle:FormTheme\\Routing' : '@WebAnyOneFormRouting/FormTheme/routing.html.twig', 'formTypeCleanMasks' => ['message' => 'raw', 'subject' => 'raw', 'banks' => 'raw', 'fallback' => 'raw'], 'eventName' => FormEvents::ON_EXECUTE_SUBMIT_ACTION, 'allowCampaignForm' => true]);
    }
    public function execute(SubmissionEvent $event) : void
    {
        if (!$event->checkContext(self::ACTION)) {
            return;
        }
        $id = $event->getAction()->getId();
        $key = 'routing_execution_' . ($id ?: spl_object_hash($event->getAction()));
        $submissionId = $event->getSubmission()->getId();
        $requestKey = 'form_routing_' . ($submissionId ?: 'new') . '_' . $id;
        if ($event->getActionFeedback($key) || $id && $submissionId && $event->getRequest()->attributes->get($requestKey)) {
            return;
        }
        if ($id && $submissionId) {
            $event->getRequest()->attributes->set($requestKey, true);
        }
        // Native cycle reuses this event for all actions; mark before transport, also on ambiguous failure.
        $event->setActionFeedback($key, 'attempted');
        $context = ['form' => $event->getForm()->getId(), 'action' => $id, 'submission' => $event->getSubmission()->getId()];
        try {
            $config = $event->getActionConfig();
            $fields = Catalogue::formFields($event->getForm());
            $results = $event->getResults();
            $alias = $config['submission_alias'] ?? '';
            if (isset($event->getPost()[$alias]) && !is_scalar($event->getPost()[$alias])) {
                $results[$alias] = [];
            } elseif (!array_key_exists($alias, $results) && isset($event->getPost()[$alias])) {
                // An unsaved routing field still has current data: apply the same native single-select filter.
                $filter = defined('MAUTIC_VERSION') && version_compare(MAUTIC_VERSION, '6.0', '>=') ? 'string' : 'clean';
                $results[$alias] = \Mautic\CoreBundle\Helper\InputHelper::_((string) $event->getPost()[$alias], $filter);
            }
            list($group, $reason, $value) = $this->router->resolve($config, $fields, $this->catalogue->all(), $results);
            $context += ['route' => $reason, 'value' => $value];
            $message = $this->composer->compose($config, $fields, $event->getResults());
            $ok = $this->sender->send($message, $group, !empty($config['set_replyto']));
            $context['status'] = $ok ? 'accepted_by_mailer_delivery_unconfirmed' : 'transport_failed';
            $event->setActionFeedback($key, $context['status']);
            $this->logger->log($ok ? 'rule' === $reason ? 'info' : 'warning' : 'error', 'form_routing', $context);
        } catch (\Throwable $e) {
            // Exception text may contain addresses, message content or credentials: log only its class.
            $context += ['status' => 'notification_failed', 'exception' => get_class($e)];
            $event->setActionFeedback($key, 'failed');
            $this->logger->error('form_routing', $context);
        }
    }
}
