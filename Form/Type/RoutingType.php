<?php

namespace MauticPlugin\WebAnyOneFormRoutingBundle\Form\Type;

use MauticPlugin\WebAnyOneFormRoutingBundle\Service\Catalogue;
use MauticPlugin\WebAnyOneFormRoutingBundle\Service\Recipients;
use MauticPlugin\WebAnyOneFormRoutingBundle\Service\Composer;
use Mautic\CoreBundle\Form\Type\YesNoButtonGroupType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Validator\Constraints\NotBlank;
final class RoutingType extends AbstractType
{
    private $catalogue;
    private $fields;
    private $recipients;
    private $translator;
    public function __construct(Catalogue $catalogue, \Mautic\FormBundle\Model\FieldModel $fields, Recipients $recipients, $translator)
    {
        $this->catalogue = $catalogue;
        $this->fields = $fields;
        $this->recipients = $recipients;
        $this->translator = $translator;
    }
    public function buildForm(FormBuilderInterface $builder, array $options) : void
    {
        $fields = $this->fields->getSessionFields($options['attr']['data-formid']);
        $catalogue = $this->catalogue->all();
        $choices = [];
        foreach ($catalogue as $alias => $field) {
            $choices[$field['label'] . ' (' . $alias . ')'] = $alias;
        }
        $builder->add('contact', ChoiceType::class, ['label' => 'webanyone.formrouting.contact', 'label_attr' => ['class' => 'control-label'], 'choices' => $choices, 'placeholder' => 'webanyone.formrouting.choose', 'attr' => ['class' => 'form-control'], 'constraints' => [new NotBlank()]]);
        $builder->add('subject', TextType::class, ['label' => 'webanyone.formrouting.subject_label', 'label_attr' => ['class' => 'control-label'], 'attr' => ['class' => 'form-control'], 'constraints' => [new NotBlank()]]);
        $builder->add('message', TextareaType::class, ['label' => 'webanyone.formrouting.message_label', 'label_attr' => ['class' => 'control-label'], 'attr' => ['class' => 'form-control editor editor-basic'], 'constraints' => [new NotBlank()]]);
        $builder->add('set_replyto', YesNoButtonGroupType::class, ['label' => 'webanyone.formrouting.replyto', 'attr' => ['tooltip' => 'webanyone.formrouting.replyto_help']]);
        $builder->add('fallback', FormType::class, ['label' => 'webanyone.formrouting.fallback']);
        $this->group($builder->get('fallback'));
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) use($fields, $catalogue) {
            $data = $event->getData() ?: [];
            $data += ['subject' => $this->translator->trans('webanyone.formrouting.subject'), 'message' => $this->defaultMessage($fields), 'set_replyto' => true, 'banks' => []];
            $definitions = $data['banks'];
            foreach ($catalogue as $alias => $field) {
                $key = Catalogue::key($alias);
                $definitions[$key]['label'] = $field['label'] . ' (' . $alias . ')';
                foreach (Catalogue::options($field) as $rowKey => $option) {
                    $definitions[$key]['rows'][$rowKey] = array_merge($definitions[$key]['rows'][$rowKey] ?? [], $option);
                }
            }
            $banks = $event->getForm()->getConfig()->getFormFactory()->createNamedBuilder('banks', FormType::class, null, ['auto_initialize' => false, 'label' => 'webanyone.formrouting.rules']);
            foreach ($definitions as $key => $bank) {
                $banks->add($key, FormType::class, ['label' => $bank['label'] ?? $key, 'translation_domain' => false]);
                $banks->get($key)->add('rows', FormType::class, ['label' => false]);
                foreach ($bank['rows'] ?? [] as $rowKey => $row) {
                    $contact = hex2bin(substr($key, 2));
                    $active = isset($catalogue[$contact]) && isset(Catalogue::options($catalogue[$contact])[$rowKey]);
                    $label = ($row['label'] ?? '') . ' [' . ($row['value'] ?? '') . ']';
                    if (!$active) {
                        $label .= ' — ' . $this->translator->trans('webanyone.formrouting.removed');
                    }
                    $rows = $banks->get($key)->get('rows');
                    $rows->add($rowKey, FormType::class, ['label' => $label, 'translation_domain' => false]);
                    $this->group($rows->get($rowKey));
                }
            }
            $event->getForm()->add($banks->getForm());
            $data['banks'] = $definitions;
            $event->setData($data);
        });
        $builder->addEventListener(FormEvents::SUBMIT, function (FormEvent $event) use($fields, $catalogue) {
            $data = $event->getData();
            $form = $event->getForm();
            try {
                if (!isset($catalogue[$data['contact']])) {
                    throw new \InvalidArgumentException('webanyone.formrouting.mapping_error');
                }
                $data['submission_alias'] = Catalogue::resolve($data['contact'], $fields);
                $data['fallback'] = $this->recipients->normalize($data['fallback'], true);
                foreach ($data['banks'] as &$bank) {
                    foreach ($bank['rows'] as &$row) {
                        $row = array_merge($row, $this->recipients->normalize($row));
                    }
                    unset($row);
                }
                unset($bank);
                // Contact tokens intentionally unsupported: they do not represent this submission.
                $template = $data['subject'] . ' ' . $data['message'];
                if (preg_match('/<[^>]*\\{formfield=/', $data['message'])) {
                    throw new \InvalidArgumentException('webanyone.formrouting.invalid_token');
                }
                preg_match_all('/\\{[^{}]+\\}/', $template, $matches);
                $allowed = array_keys($this->tokens($fields));
                foreach ($matches[0] as $token) {
                    if (!in_array($token, $allowed, true)) {
                        throw new \InvalidArgumentException('webanyone.formrouting.invalid_token');
                    }
                }
            } catch (\InvalidArgumentException $e) {
                $form->addError(new FormError($this->translator->trans($e->getMessage())));
            }
            $event->setData($data);
        });
    }
    private function group(FormBuilderInterface $builder) : void
    {
        foreach (['to', 'cc', 'bcc'] as $kind) {
            $builder->add($kind, TextType::class, ['label' => 'webanyone.formrouting.' . $kind, 'label_attr' => ['class' => 'control-label'], 'translation_domain' => 'messages', 'required' => false, 'empty_data' => '', 'attr' => ['class' => 'form-control', 'autocomplete' => 'off']]);
        }
    }
    private function tokens(array $fields) : array
    {
        $tokens = [];
        foreach ($fields as $field) {
            if (Composer::allowed($field)) {
                $tokens['{formfield=' . $field['alias'] . '}'] = $field['label'];
            }
        }
        return $tokens;
    }
    private function defaultMessage(array $fields) : string
    {
        $message = '';
        foreach ($this->tokens($fields) as $token => $label) {
            $message .= '<p><strong>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</strong>: ' . $token . '</p>';
        }
        return $message;
    }
    public function buildView(FormView $view, FormInterface $form, array $options) : void
    {
        $view->vars['routingSelectedKey'] = Catalogue::key((string) ($form->get('contact')->getData() ?? ''));
        $view->vars['routingTokens'] = $this->tokens($this->fields->getSessionFields($options['attr']['data-formid']));
    }
    public function getBlockPrefix() : string
    {
        return 'webanyone_form_routing';
    }
}
