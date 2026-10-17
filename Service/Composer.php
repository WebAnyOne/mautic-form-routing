<?php

namespace MauticPlugin\WebAnyOneFormRoutingBundle\Service;

final class Composer
{
    public static function allowed(array $field) : bool
    {
        return in_array($field['type'] ?? '', ['text', 'textarea', 'email', 'tel', 'number', 'select', 'date', 'datetime', 'url', 'checkboxgrp', 'radiogrp', 'country'], true) && (!array_key_exists('saveResult', $field) || $field['saveResult']);
    }
    public function compose(array $config, array $fields, array $results) : array
    {
        $html = [];
        $plain = [];
        $emails = [];
        foreach ($fields as $field) {
            if (!self::allowed($field)) {
                continue;
            }
            $value = $results[$field['alias']] ?? '';
            $value = is_scalar($value) ? SubmissionValue::text((string) $value, $field['type']) : '';
            $token = '{formfield=' . $field['alias'] . '}';
            $plain[$token] = $value;
            // Also encode braces, preventing submitted content being interpreted as native email tokens.
            $html[$token] = nl2br(str_replace(['{', '}'], ['&#123;', '&#125;'], htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')));
            if ('email' === Catalogue::contactAlias($field)) {
                $emails[] = trim($value);
            }
        }
        $subject = strtr($config['subject'], $plain);
        $subject = str_replace(["\r", "\n", '{', '}'], [' ', ' ', '(', ')'], $subject);
        return ['subject' => $subject, 'body' => strtr($config['message'], $html), 'reply' => count($emails) === 1 ? $emails[0] : null];
    }
}
