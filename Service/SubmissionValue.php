<?php

namespace MauticPlugin\WebAnyOneFormRoutingBundle\Service;

/** Adapter for the native FormFieldHelper defaults in the three qualified releases. */
final class SubmissionValue
{
    public static function text(string $value, string $type = 'select') : string
    {
        if (in_array($type, ['email', 'url', 'number'], true) || defined('MAUTIC_VERSION') && version_compare(MAUTIC_VERSION, '6.0', '>=')) {
            return $value;
        }
        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
