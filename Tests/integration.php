<?php

// CLI only. No SMTP is used: native MailHelper is intercepted by a capture mailer.
if ('cli' !== PHP_SAPI) {
    exit(1);
}
$lab = $argv[1] ?? '';
$source = dirname(__DIR__);
chdir($lab);
require $lab . '/autoload.php';
$_SERVER['MAUTIC_TABLE_PREFIX'] = '';
$kernel = new AppKernel('prod', false);
$kernel->boot();
$c = $kernel->getContainer();
$em = $c->get('doctrine.orm.entity_manager');
$db = $em->getConnection();
if (strpos($db->getDatabase(), 'apimonitor_lab_') !== 0) {
    throw new RuntimeException('Disposable apimonitor_lab_* only');
}
use MauticPlugin\WebAnyOneFormRoutingBundle\Service\Catalogue;
use MauticPlugin\WebAnyOneFormRoutingBundle\Service\Composer;
use MauticPlugin\WebAnyOneFormRoutingBundle\Service\Router;
use MauticPlugin\WebAnyOneFormRoutingBundle\Form\Type\RoutingType;
use MauticPlugin\WebAnyOneFormRoutingBundle\EventListener\FormSubscriber;
use Mautic\FormBundle\Entity\Form;
use Mautic\FormBundle\Entity\Action;
use Mautic\FormBundle\Entity\Submission;
use Mautic\FormBundle\Event\SubmissionEvent;
use Symfony\Component\HttpFoundation\Request;
$n = 0;
function check($ok, $message)
{
    global $n;
    if (!$ok) {
        throw new RuntimeException($message);
    }
    ++$n;
}
function prop($object, $name, $value)
{
    $r = new ReflectionObject($object);
    while (!$r->hasProperty($name)) {
        $r = $r->getParentClass();
    }
    $p = $r->getProperty($name);
    $p->setAccessible(true);
    $p->setValue($object, $value);
}
$translator = $c->get('translator');
foreach (['fr', 'fr_FR'] as $locale) {
    check($translator->trans('webanyone.formrouting.contact', [], 'messages', $locale) === 'Champ contact de routage', 'French contact label '.$locale);
    check($translator->trans('webanyone.formrouting.fallback', [], 'messages', $locale) === 'Destinataire de secours', 'French fallback label '.$locale);
    check($translator->trans('webanyone.formrouting.title', [], 'messages', $locale) === 'Envoyer les résultats selon une liste', 'Action title '.$locale);
}
$translator->setLocale('fr');
$r = $c->get('webanyone.formrouting.recipients');
check($r->normalize(['to' => 'a@example.invalid; b@example.invalid', 'cc' => 'A@example.invalid, c@example.invalid', 'bcc' => 'c@example.invalid;d@example.invalid']) === ['to' => 'a@example.invalid, b@example.invalid', 'cc' => 'c@example.invalid', 'bcc' => 'd@example.invalid'], 'dedup');
foreach (['bad', '{contactfield=email}', "a@example.invalid\r\nBcc:x@example.invalid"] as $bad) {
    try {
        $r->normalize(['to' => $bad]);
        check(false, 'invalid accepted');
    } catch (InvalidArgumentException $e) {
        check(true, 'invalid rejected');
    }
}
try {
    $r->normalize(['cc' => 'a@example.invalid']);
    check(false, 'cc only');
} catch (InvalidArgumentException $e) {
    check(true, 'cc only rejected');
}
$alias = 'routing_fixture';
$leadField = new \Mautic\LeadBundle\Entity\LeadField();
$leadField->setLabel('Agence de rattachement');
$leadField->setAlias($alias);
$leadField->setType('select');
$leadField->setObject('lead');
$leadField->setGroup('core');
$options = [];
foreach (['01', '1', '2', '3', '4', '5', '6', '7', '8', 'empty'] as $value) {
    $options[] = ['value' => $value, 'label' => 'Agence ' . $value];
}
$leadField->setProperties(['list' => $options]);
$c->get('mautic.lead.model.field')->saveEntity($leadField);
try {
    $fields = [['alias' => 'agency', 'label' => 'Agence', 'type' => 'select', 'mappedObject' => 'contact', 'mappedField' => $alias, 'properties' => ['multiple' => false], 'saveResult' => true], ['alias' => 'email', 'label' => 'E-mail', 'type' => 'email', 'mappedObject' => 'contact', 'mappedField' => 'email', 'saveResult' => true], ['alias' => 'message', 'label' => 'Message', 'type' => 'textarea', 'saveResult' => true], ['alias' => 'password', 'label' => 'Password', 'type' => 'password', 'saveResult' => true], ['alias' => 'file', 'label' => 'File', 'type' => 'file', 'saveResult' => true]];
    $catalogue = $c->get('webanyone.formrouting.catalogue')->all();
    check(isset($catalogue[$alias]), 'native catalogue');
    $config = ['contact' => $alias, 'submission_alias' => 'agency', 'fallback' => ['to' => 'fallback@example.invalid', 'cc' => '', 'bcc' => ''], 'banks' => [], 'subject' => 'Demande {formfield=agency}', 'message' => '<p>{formfield=message}</p><p>{formfield=email}</p>', 'set_replyto' => true];
    foreach ($options as $option) {
        $config['banks'][Catalogue::key($alias)]['rows'][Catalogue::key($option['value'])] = array_merge($option, ['to' => $option['value'] === 'empty' ? '' : 'agency' . $option['value'] . '@example.invalid', 'cc' => '', 'bcc' => '']);
    }
    $config['banks'][Catalogue::key($alias)]['label'] = 'Agency';
    $router = new Router();
    foreach (['01', '1', '2', '3', '4', '5', '6', '7', '8'] as $value) {
        $res = $router->resolve($config, $fields, $catalogue, ['agency' => $value]);
        check($res[0]['to'] === 'agency' . $value . '@example.invalid', 'route ' . $value);
    }
    foreach ([null, '', 'unknown', ['1', '2'], 'empty', 1] as $value) {
        check($router->resolve($config, $fields, $catalogue, ['agency' => $value])[0]['to'] === 'fallback@example.invalid', 'fallback');
    }
    check($router->resolve($config, [], $catalogue, ['agency' => '1'])[1] === 'broken_mapping', 'broken mapping');
    $changed = $catalogue;
    $changed[$alias]['properties']['list'][1]['label'] = 'Renamed';
    check($router->resolve($config, $fields, $changed, ['agency' => '1'])[1] === 'rule', 'rename stable');
    $changed[$alias]['properties']['list'] = [];
    check($router->resolve($config, $fields, $changed, ['agency' => '1'])[1] === 'unknown', 'removed');
    $composed = (new Composer())->compose($config, $fields, ['agency' => '1', 'email' => 'prospect@example.invalid', 'message' => version_compare(MAUTIC_VERSION, '6.0', '>=') ? "Été\n<script>alert(1)</script> {contactfield=email}" : "Été\n&lt;script&gt;alert(1)&lt;/script&gt; {contactfield=email}"]);
    check(strpos($composed['body'], '<script>') === false && strpos($composed['body'], '&lt;script&gt;') !== false, 'HTML escaped');
    check(strpos($composed['body'], '<br') !== false && strpos($composed['body'], 'Été') !== false, 'newline and unicode');
    check(strpos($composed['body'], '{contactfield=') === false, 'no token injection');
    check($composed['reply'] === 'prospect@example.invalid', 'reply current');
    // Real Symfony action form, using the native session field store.
    $request = Request::create('/s/forms');
    $session = $c->has('session') ? $c->get('session') : new \Symfony\Component\HttpFoundation\Session\Session(new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage());
    $request->setSession($session);
    $c->get('request_stack')->push($request);
    $session->set('mautic.form.routing-test.fields.modified', $fields);
    if ($c->has('form.factory')) {
        $factory = $c->get('form.factory');
    } else {
        $controller = $c->get(\Mautic\CoreBundle\Controller\FormController::class);
        $reflection = new ReflectionObject($controller);
        $property = $reflection->getProperty('formFactory');
        $property->setAccessible(true);
        $factory = $property->getValue($controller);
    }
    $make = function ($data) use($factory) {
        return $factory->createNamed('properties', RoutingType::class, $data, ['csrf_protection' => false, 'attr' => ['data-formid' => 'routing-test']]);
    };
    $form = $make($config);
    $input = $config;
    unset($input['submission_alias']);
    // Browsers only post actual form controls, not persisted metadata.
    $controls = function ($node) use(&$controls) {
        $data = [];
        foreach ($node as $key => $child) {
            $data[$key] = is_array($child->getViewData()) ? $controls($child) : $child->getViewData();
        }
        return $data;
    };
    $input = $controls($form);
    $form->submit($input);
    if (!$form->isValid()) {
        throw new RuntimeException((string) $form->getErrors(true, false));
    }
    $saved = $form->getData();
    check($saved['submission_alias'] === 'agency', 'resolved alias persisted');
    check($saved['banks'][Catalogue::key($alias)]['rows'][Catalogue::key('01')]['to'] === 'agency01@example.invalid', 'config roundtrip');
    check(isset($saved['banks'][Catalogue::key($alias)]['rows'][Catalogue::key('01')]['value']), 'metadata preserved');
    $reopened = $make(unserialize(serialize($saved)));
    check($reopened->get('fallback')->get('to')->getData() === 'fallback@example.invalid', 'reopen/duplicate');
    $invalid = $make($saved);
    $bad = $controls($invalid);
    $bad['fallback']['to'] = 'invalid';
    $invalid->submit($bad);
    check(!$invalid->isValid(), 'server rejects address');
    $evolved = $options;
    $evolved[0]['label'] = 'Renamed zero';
    unset($evolved[1]);
    $evolved[] = ['value' => 'new', 'label' => 'New option'];
    $leadField->setProperties(['list' => array_values($evolved)]);
    $em->persist($leadField);
    $em->flush();
    $updated = $make($saved);
    $bank = $updated->get('banks')->get(Catalogue::key($alias))->get('rows');
    check($bank->has(Catalogue::key('new')) && !$bank->get(Catalogue::key('new'))->get('to')->getData(), 'new option empty');
    check($bank->get(Catalogue::key('01'))->get('to')->getData() === 'agency01@example.invalid', 'renamed option preserved');
    check($bank->has(Catalogue::key('1')) && $bank->get(Catalogue::key('1'))->get('to')->getData() === 'agency1@example.invalid', 'removed option retained');
    $leadField->setProperties(['list' => $options]);
    $em->persist($leadField);
    $em->flush();
    // Capture below the actual Mautic mail helper. This never connects to SMTP.
    $helper = $c->get('mautic.helper.mailer');
    if (version_compare(MAUTIC_VERSION, '5.0', '<')) {
        $capture = new class implements \Swift_Transport
        {
            public $messages = [];
            public $fail = false;
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
            public function send(\Swift_Mime_SimpleMessage $message, &$failedRecipients = null)
            {
                if ($this->fail) {
                    throw new \Swift_TransportException('synthetic');
                }
                $this->messages[] = clone $message;
                return 1;
            }
            public function registerPlugin(\Swift_Events_EventListener $plugin)
            {
            }
        };
        prop($helper, 'mailer', new \Swift_Mailer($capture));
        prop($helper, 'transport', $capture);
    } else {
        $capture = new class implements \Symfony\Component\Mailer\MailerInterface
        {
            public $messages = [];
            public $fail = false;
            public function send(\Symfony\Component\Mime\RawMessage $message, ?\Symfony\Component\Mailer\Envelope $envelope = null) : void
            {
                if ($this->fail) {
                    throw new \Symfony\Component\Mailer\Exception\TransportException('synthetic');
                }
                $this->messages[] = clone $message;
            }
        };
        prop($helper, 'mailer', $capture);
    }
    $subscriber = $c->get('webanyone.formrouting.subscriber');
    $build = new \Mautic\FormBundle\Event\FormBuilderEvent($c->get('translator'));
    $subscriber->build($build);
    $actions = $build->getSubmitActions();
    check(!empty($actions[FormSubscriber::ACTION]['allowCampaignForm']), 'campaign editor enabled');
    $native = new Form();
    $native->setName('Routing fixture');
    foreach ($fields as $i => $def) {
        $field = new \Mautic\FormBundle\Entity\Field();
        $field->setAlias($def['alias']);
        $field->setLabel($def['label']);
        $field->setType($def['type']);
        $field->setSaveResult(true);
        $field->setProperties($def['properties'] ?? []);
        if (isset($def['mappedField'])) {
            if (method_exists($field, 'setMappedObject')) {
                $field->setMappedObject('contact');
                $field->setMappedField($def['mappedField']);
            } else {
                $field->setLeadField($def['mappedField']);
            }
        }
        $native->addField($i, $field);
    }
    $action = new Action();
    $action->setType(FormSubscriber::ACTION);
    $action->setProperties($saved);
    $action->setName('Routing');
    $lead = new \Mautic\LeadBundle\Entity\Lead();
    $lead->setEmail('old-contact@example.invalid');
    $eventFor = function ($agency, $message) use($native, $lead, $action, $fields) {
        $s = new Submission();
        $s->setForm($native);
        $s->setLead($lead);
        $event = new SubmissionEvent($s, [], [], Request::create('/form/submit', 'POST'));
        $event->setAction($action);
        $event->setFields($fields);
        $event->setResults(['agency' => $agency, 'message' => $message, 'email' => 'current@example.invalid']);
        return $event;
    };
    $event = $eventFor('1', 'Premier {signature}');
    $subscriber->execute($event);
    $subscriber->execute($event);
    check(count($capture->messages) === 1, 'double internal trigger');
    $subscriber->execute($eventFor('1', 'Premier'));
    $subscriber->execute($eventFor('2', 'Second'));
    check(count($capture->messages) === 3, 'same contact repeat submissions');
    $addresses = function ($m, $kind) {
        $v = $m->{'get' . $kind}();
        return version_compare(MAUTIC_VERSION, '5.0', '<') ? array_keys((array) $v) : array_map(static function ($a) {
            return $a->getAddress();
        }, $v);
    };
    check($addresses($capture->messages[0], 'To') === ['agency1@example.invalid'], 'first recipients');
$plain = method_exists($capture->messages[0], 'getTextBody') ? $capture->messages[0]->getTextBody() : $capture->messages[0]->getChildren()[0]->getBody();
check(strpos($plain, '(signature)') !== false, 'plaintext cannot reinterpret submitted native tokens');
    check($addresses($capture->messages[2], 'To') === ['agency2@example.invalid'], 'second recipients');
    check($addresses($capture->messages[2], 'ReplyTo') === ['current@example.invalid'], 'reply not old contact');
    $body = function ($m) {
        return method_exists($m, 'getHtmlBody') ? $m->getHtmlBody() : $m->getBody();
    };
    check(strpos($body($capture->messages[0]), 'Premier') !== false && strpos($body($capture->messages[2]), 'Second') !== false, 'frozen messages');
    $subscriber->execute($eventFor('unknown', 'Fallback'));
    check(count($capture->messages) === 4 && $addresses($capture->messages[3], 'To') === ['fallback@example.invalid'], 'fallback capture');
    $capture->fail = true;
    $failed = $eventFor('1', 'Transport failure');
    $subscriber->execute($failed);
    $subscriber->execute($failed);
    check(count($capture->messages) === 4, 'transport failure no fallback/retry');
    check(in_array('transport_failed', $failed->getActionFeedback(), true) || in_array('failed', $failed->getActionFeedback(), true), 'failure status');
    // Render the actual modal with its native action parent, clean masks and CSRF field.
    $settings = $actions[FormSubscriber::ACTION];
    $actionForm = $factory->create(\Mautic\FormBundle\Form\Type\ActionType::class, ['name' => 'Notification interne', 'type' => FormSubscriber::ACTION, 'properties' => $saved], ['settings' => $settings, 'formId' => 'routing-test']);
    $actionInput = $controls($actionForm);
    $actionInput['_token'] = 'invalid-token';
    $actionForm->submit($actionInput);
    check(!$actionForm->isValid(), 'native parent rejects invalid CSRF');
    $actionForm = $factory->create(\Mautic\FormBundle\Form\Type\ActionType::class, ['name' => 'Notification interne', 'type' => FormSubscriber::ACTION, 'properties' => $saved], ['settings' => $settings, 'formId' => 'routing-test']);
    $parentView = $actionForm->createView();
    $actionInput = $controls($actionForm);
    $actionInput['_token'] = $parentView['_token']->vars['value'];
    $actionForm->submit($actionInput);
    check($actionForm->isValid(), 'native parent valid CSRF and clean masks: ' . (string) $actionForm->getErrors(true, false));
    check($actionForm->getData()['properties']['banks'][Catalogue::key($alias)]['rows'][Catalogue::key('01')]['to'] === 'agency01@example.invalid', 'native parent rules preserved');
    $actionForm = $factory->create(\Mautic\FormBundle\Form\Type\ActionType::class, ['name' => 'Notification interne', 'type' => FormSubscriber::ACTION, 'properties' => $saved], ['settings' => $settings, 'formId' => 'routing-test']);
    $view = $actionForm->createView();
    if (version_compare(MAUTIC_VERSION, '5.0', '<')) {
        $engine = $c->get('mautic.helper.templating')->getTemplating();
        if ($engine instanceof \Symfony\Bundle\FrameworkBundle\Templating\DelegatingEngine) {
            $engine = $engine->getEngine('MauticFormBundle:Builder:action.html.php');
        }
        $engine->get('form')->setTheme($view, [$settings['formTheme'], 'MauticCoreBundle:FormTheme\\Custom']);
        $html = $engine->render('MauticFormBundle:Builder:action.html.php', ['form' => $view, 'actionHeader' => 'WebAnyOne — Routage des résultats']);
    } else {
        $twig = $c->has('twig') ? $c->get('twig') : null;
        if (!$twig) {
            $p = (new ReflectionObject($helper))->getProperty('twig');
            $p->setAccessible(true);
            $twig = $p->getValue($helper);
        }
        $twig->enableAutoReload();
        $html = $twig->render('@MauticForm/Builder/action.html.twig', ['form' => $view, 'formTheme' => $settings['formTheme'], 'actionHeader' => 'WebAnyOne — Routage des résultats']);
    }
    file_put_contents('/tmp/routing-ui-' . MAUTIC_VERSION . '.html', '<!doctype html><html><head><meta charset="utf-8"><link rel="stylesheet" href="/media/css/libraries.css"><link rel="stylesheet" href="/media/css/app.css"></head><body><main style="max-width:960px;margin:30px auto;padding:24px">' . $html . '</main></body></html>');
    check(strpos($html, 'webanyoneFormRoutingTokens') !== false && strpos($html, 'agency01@example.invalid') !== false, 'native configuration rendered');
    check(strpos($html, '_token') !== false, 'native CSRF control');
    check(strpos($html, 'Champ contact de routage') !== false && strpos($html, 'Routing contact field') === false, 'French rendered field selector');
    check(substr_count($html, 'Destinataire de secours') === 1 && strpos($html, 'Fallback recipients') === false, 'Only the useful fallback group heading remains');
    check(strpos(strip_tags($html), 'Objet') !== false && strpos($html, 'Répondre au contact') !== false, 'French composition controls');
    // To/Cc/Bcc are captured independently, without including recipients in content.
    $capture->fail = false;
    $withCc = $saved;
    $withCc['banks'][Catalogue::key($alias)]['rows'][Catalogue::key('1')] = ['to' => 'agency1@example.invalid', 'cc' => 'copy@example.invalid', 'bcc' => 'blind@example.invalid'];
    $action->setProperties($withCc);
    $subscriber->execute($eventFor('1', 'Group headers'));
    $last = end($capture->messages);
    check($addresses($last, 'Cc') === ['copy@example.invalid'] && $addresses($last, 'Bcc') === ['blind@example.invalid'], 'Cc Bcc native headers');
    check(strpos($body($last), 'blind@example.invalid') === false, 'Bcc absent from body');
    // Serialization at queue boundary: mutate the contact/action afterwards, then inspect thawed messages.
    $frozen = serialize($capture->messages);
    $lead->setEmail('changed@example.invalid');
    $action->setProperties([]);
    $thawed = unserialize($frozen);
    check($addresses($thawed[0], 'To') === ['agency1@example.invalid'] && $addresses($thawed[2], 'To') === ['agency2@example.invalid'], 'serialized envelopes frozen');
    check(strpos($body($thawed[0]), 'Premier') !== false && strpos($body($thawed[2]), 'Second') !== false, 'serialized content frozen');
    // Execute both real action listeners in sequence: shared MailHelper must not leak recipients or body.
    $lead->setFields(['core'=>[]]);
    $nativeAction = new Action();
    $nativeAction->setType('form.email');
    $nativeAction->setProperties(['to'=>'native@example.invalid', 'cc'=>'', 'bcc'=>'', 'subject'=>'Native subject', 'message'=>'Native {formfield=message}', 'immediately'=>false, 'set_replyto'=>false, 'copy_lead'=>false, 'email_to_owner'=>false]);
    $dispatch = function ($event) use ($c) {
        $dispatcher = $c->get('event_dispatcher');
        if (version_compare(MAUTIC_VERSION, '5.0', '<')) { $dispatcher->dispatch(\Mautic\FormBundle\FormEvents::ON_EXECUTE_SUBMIT_ACTION, $event); }
        else { $dispatcher->dispatch($event, \Mautic\FormBundle\FormEvents::ON_EXECUTE_SUBMIT_ACTION); }
    };
    $nativeEvent = function () use ($eventFor, $nativeAction) {
        $event = $eventFor('1', 'Native body');
        $event->setAction($nativeAction);
        $event->setTokens(['{formfield=message}'=>'Native body']);
        return $event;
    };
    $beforeInterop = count($capture->messages);
    $action->setProperties($withCc);
    $dispatch($eventFor('1', 'WebAnyOne before native'));
    $dispatch($nativeEvent());
    $nativeAfter = end($capture->messages);
    check(count($capture->messages) === $beforeInterop+2, 'Both distinct action listeners execute exactly once');
    check($addresses($nativeAfter, 'To') === ['native@example.invalid'], 'Native recipients isolated after WebAnyOne');
    check($addresses($nativeAfter, 'Cc') === [] && $addresses($nativeAfter, 'Bcc') === [], 'Native mail does not inherit WebAnyOne Cc/Bcc');
    check(strpos($body($nativeAfter), 'Native body') !== false && strpos($body($nativeAfter), 'WebAnyOne before native') === false, 'Native body isolated after WebAnyOne');
    $dispatch($eventFor('2', 'WebAnyOne after native'));
    $customAfter = end($capture->messages);
    check(count($capture->messages) === $beforeInterop+3 && $addresses($customAfter, 'To') === ['agency2@example.invalid'], 'WebAnyOne recipients isolated after native');
    check(strpos($body($customAfter), 'WebAnyOne after native') !== false && strpos($body($customAfter), 'Native body') === false, 'WebAnyOne body isolated after native');
    $action->setProperties([]);
    // Full native saveSubmission cycle, for both standalone and campaign forms.
    $capture->fail = false;
    $formModel = $c->get('mautic.form.model.form');
    $submissionModel = $c->get('mautic.form.model.submission');
    $created = [];
    $createdContacts = [];
    try {
        foreach (['standalone', 'campaign'] as $formType) {
            $full = new Form();
            $full->setName('Routing integration ' . $formType);
            $full->setFormType($formType);
            $full->setIsPublished(true);
            $full->setPostAction('return');
            if (method_exists($full, 'setLanguage')) {
                $full->setLanguage('en');
            }
            foreach (array_slice($fields, 0, 3) as $i => $def) {
                $field = new \Mautic\FormBundle\Entity\Field();
                $field->setForm($full);
                $field->setLabel($def['label']);
                $field->setAlias($def['alias']);
                $field->setType($def['type']);
                $field->setOrder($i + 1);
                $field->setSaveResult(true);
                if (isset($def['mappedObject'])) {
                    if (method_exists($field, 'setMappedObject')) {
                        $field->setMappedObject($def['mappedObject']);
                        $field->setMappedField($def['mappedField']);
                    } else {
                        $field->setLeadField($def['mappedField']);
                    }
                }
                if ($def['type'] === 'select') {
                    $field->setProperties(['list' => $options, 'multiple' => false]);
                }
                $full->addField($i, $field);
            }
            $fullAction = new Action();
            $fullAction->setName('Routing');
            $fullAction->setType(FormSubscriber::ACTION);
            $fullAction->setProperties($saved);
            $fullAction->setForm($full);
            $full->addAction(0, $fullAction);
            $formModel->saveEntity($full);
            $created[] = $full;
            check($fullAction->getId() !== null, 'action persisted ' . $formType);
            $em->refresh($fullAction);
            check($fullAction->getProperties() === $saved, 'database configuration roundtrip ' . $formType);
            $clonedAction = clone $fullAction;
            check($clonedAction->getProperties() === $saved, 'native action clone ' . $formType);
            $copyProperties = $clonedAction->getProperties();
            $copyProperties['fallback']['to'] = 'other@example.invalid';
            $clonedAction->setProperties($copyProperties);
            check($fullAction->getProperties()['fallback']['to'] === 'fallback@example.invalid', 'clone independent ' . $formType);
            $public = $formModel->getContent($full, false, false);
            check(strpos($public, 'fallback@example.invalid') === false && strpos($public, 'agency1@example.invalid') === false, 'no public recipients ' . $formType);
            $start = count($capture->messages);
            $ids = [];
            $contactIds = [];
            foreach ([['1', 'Premier complet'], ['1', 'Premier complet'], ['2', 'Deuxième & <b>HTML</b>']] as $j => $row) {
                $post = ['formId' => $full->getId(), 'formName' => $full->getName(), 'agency' => $row[0], 'message' => $row[1], 'email' => 'pipeline-' . $formType . '@example.invalid', 'return' => 'http://localhost/'];
                $req = Request::create('/form/submit', 'POST', ['mauticform' => $post]);
                $req->setSession($session);
                $c->get('request_stack')->push($req);
                try {
                    $result = $submissionModel->saveSubmission($post, [], $full, $req, true);
                } finally {
                    $c->get('request_stack')->pop();
                }
                $result = $result['submission'] ?? $result;
                check($result instanceof SubmissionEvent, 'native successful submission ' . $formType . ' ' . json_encode(is_array($result) ? $result : []));
                $ids[] = $result->getSubmission()->getId();
                $contact = $result->getSubmission()->getLead();
                $contactIds[] = $contact->getId();
                $createdContacts[$contact->getId()] = $contact;
                if ($j === 0) {
                    $dnc = new \Mautic\LeadBundle\Entity\DoNotContact();
                    $dnc->setLead($contact);
                    $dnc->setChannel('email');
                    $dnc->setReason(1);
                    $dnc->setDateAdded(new DateTime());
                    $em->persist($dnc);
                    $em->flush();
                }
            }
            check(count(array_unique($ids)) === 3, 'three stored submissions ' . $formType);
            check(count(array_unique($contactIds)) === 1, 'same contact ' . $formType);
            check(count($capture->messages) === $start + 3, 'DNC still notified ' . $formType);
            check($addresses($capture->messages[$start + 2], 'To') === ['agency2@example.invalid'], 'native technical select value ' . $formType);
            check(strpos($body($capture->messages[$start + 2]), version_compare(MAUTIC_VERSION, '6.0', '>=') ? '&amp; HTML' : '&lt;b&gt;HTML&lt;/b&gt;') !== false && strpos($body($capture->messages[$start + 2]), '<b>') === false, 'native cleaned HTML safe ' . $formType);
            $capture->fail = true;
            $post['message'] = 'Transport failure saved';
            $req = Request::create('/form/submit', 'POST', ['mauticform' => $post]);
            $req->setSession($session);
            $c->get('request_stack')->push($req);
            try {
                $failedResult = $submissionModel->saveSubmission($post, [], $full, $req, true);
            } finally {
                $c->get('request_stack')->pop();
            }
            $failedResult = $failedResult['submission'] ?? $failedResult;
            check($failedResult instanceof SubmissionEvent && $failedResult->getSubmission()->getId() !== null, 'failed transport keeps submission ' . $formType);
            check(count($capture->messages) === $start + 3, 'failed transport no fallback ' . $formType);
            $capture->fail = false;
            $dispatcher = $c->get('event_dispatcher');
            $dispatcher->removeSubscriber($subscriber);
            try {
                $noAction = $submissionModel->saveSubmission($post, [], $full, $req, true);
            } finally {
                $dispatcher->addSubscriber($subscriber);
            }
            check(isset($noAction['submission']) && count($capture->messages) === $start + 3, 'disabled subscriber preserves submission ' . $formType);
        }
    } finally {
        foreach ($created as $fixture) {
            $formModel->deleteEntity($fixture);
        }
        foreach ($createdContacts as $fixture) {
            $c->get('mautic.lead.model.lead')->deleteEntity($fixture);
        }
    }
    // Native queued sending: Swift file spool or Symfony Mailer + Messenger envelope/handler.
    $action->setProperties($withCc);
    if (version_compare(MAUTIC_VERSION, '5.0', '<')) {
        $spoolPath = sys_get_temp_dir() . '/routing-spool-' . bin2hex(random_bytes(6));
        mkdir($spoolPath, 0700);
        $spool = new \Swift_FileSpool($spoolPath);
        $spoolTransport = new \Swift_SpoolTransport($spool);
        prop($helper, 'mailer', new \Swift_Mailer($spoolTransport));
        prop($helper, 'transport', $spoolTransport);
        $before = count($capture->messages);
        $subscriber->execute($eventFor('1', 'Queued A'));
        $subscriber->execute($eventFor('2', 'Queued B'));
        check(count(glob($spoolPath . '/*.message')) === 2, 'two native spool files');
        $action->setProperties([]);
        $lead->setEmail('newer@example.invalid');
        $spool->flushQueue($capture);
        check(count($capture->messages) === $before + 2, 'native spool flush');
        $queued = array_slice($capture->messages, -2);
        usort($queued, static function ($a, $b) {
            return strcmp($a->getSubject(), $b->getSubject());
        });
        check($addresses($queued[0], 'To') === ['agency1@example.invalid'] && strpos($body($queued[0]), 'Queued A') !== false, 'native spool snapshot A');
        check($addresses($queued[1], 'To') === ['agency2@example.invalid'] && strpos($body($queued[1]), 'Queued B') !== false, 'native spool snapshot B');
        rmdir($spoolPath);
    } else {
        $transport = new class extends \Symfony\Component\Mailer\Transport\AbstractTransport
        {
            public $messages = [];
            protected function doSend(\Symfony\Component\Mailer\SentMessage $message) : void
            {
                $this->messages[] = $message;
            }
            public function __toString() : string
            {
                return 'routing-capture://';
            }
        };
        $bus = new class implements \Symfony\Component\Messenger\MessageBusInterface
        {
            public $messages = [];
            public function dispatch(object $message, array $stamps = []) : \Symfony\Component\Messenger\Envelope
            {
                $envelope = new \Symfony\Component\Messenger\Envelope($message, $stamps);
                $this->messages[] = serialize($envelope);
                return $envelope;
            }
        };
        prop($helper, 'mailer', new \Symfony\Component\Mailer\Mailer($transport, $bus));
        prop($helper, 'transport', $transport);
        $subscriber->execute($eventFor('1', 'Queued A'));
        $subscriber->execute($eventFor('2', 'Queued B'));
        check(count($bus->messages) === 2 && count($transport->messages) === 0, 'queued before worker');
        $action->setProperties([]);
        $lead->setEmail('newer@example.invalid');
        $handler = new \Symfony\Component\Mailer\Messenger\MessageHandler($transport);
        foreach ($bus->messages as $serialized) {
            $handler(unserialize($serialized)->getMessage());
        }
        check(count($transport->messages) === 2, 'native Messenger handler');
        check($addresses($transport->messages[0]->getOriginalMessage(), 'To') === ['agency1@example.invalid'] && strpos($body($transport->messages[0]->getOriginalMessage()), 'Queued A') !== false, 'Messenger snapshot A');
        check($addresses($transport->messages[1]->getOriginalMessage(), 'To') === ['agency2@example.invalid'] && strpos($body($transport->messages[1]->getOriginalMessage()), 'Queued B') !== false, 'Messenger snapshot B');
        check(stripos($transport->messages[0]->toString(), "\nBcc:") === false, 'native transport strips Bcc header');
        check(count($transport->messages[0]->getEnvelope()->getRecipients()) === 3, 'Bcc retained in envelope');
    }
    echo json_encode(['mautic' => MAUTIC_VERSION, 'php' => PHP_VERSION, 'assertions' => $n, 'captured' => count($capture->messages), 'status' => 'PASS'], JSON_PRETTY_PRINT) . "\n";
} finally {
    $c->get('mautic.lead.model.field')->deleteEntity($leadField);
}
