<?php

namespace MauticPlugin\WebAnyOneFormRoutingBundle\Service;

final class Catalogue
{
    private $contactFields;
    public function __construct(\Mautic\LeadBundle\Model\FieldModel $contactFields)
    {
        $this->contactFields = $contactFields;
    }
    public static function key(string $value) : string
    {
        return 'v_' . bin2hex($value);
    }
    public function all() : array
    {
        return array_filter($this->contactFields->getFieldListWithProperties('lead'), static function ($f) {
            return 'select' === $f['type'];
        });
    }
    public static function options(array $field) : array
    {
        $out = [];
        foreach ($field['properties']['list'] ?? [] as $option) {
            if (!is_array($option) || !isset($option['value'])) {
                continue;
            }
            $value = (string) $option['value'];
            $out[self::key($value)] = ['value' => $value, 'label' => (string) ($option['label'] ?? $value)];
        }
        return $out;
    }
    public static function contactAlias(array $field) : ?string
    {
        if (!empty($field['mappedObject'])) {
            return $field['mappedObject'] === 'contact' ? $field['mappedField'] ?? null : null;
        }
        return $field['leadField'] ?? null;
    }
    public static function formFields($form) : array
    {
        $fields = [];
        foreach ($form->getFields() as $field) {
            $data = ['alias' => $field->getAlias(), 'label' => $field->getLabel(), 'type' => $field->getType(), 'properties' => $field->getProperties(), 'saveResult' => $field->getSaveResult()];
            if (method_exists($field, 'getMappedObject')) {
                $data['mappedObject'] = $field->getMappedObject();
                $data['mappedField'] = $field->getMappedField();
            }
            $data['leadField'] = $field->getLeadField();
            $fields[] = $data;
        }
        return $fields;
    }
    public static function resolve(string $contactAlias, array $fields) : string
    {
        $matches = [];
        foreach ($fields as $field) {
            if (self::contactAlias($field) === $contactAlias) {
                if ('select' !== ($field['type'] ?? '') || !empty($field['properties']['multiple'])) {
                    throw new \InvalidArgumentException('webanyone.formrouting.not_single');
                }
                $matches[] = $field['alias'];
            }
        }
        if (1 !== count($matches)) {
            throw new \InvalidArgumentException('webanyone.formrouting.mapping_error');
        }
        return $matches[0];
    }
}
