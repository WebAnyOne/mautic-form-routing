<?php

namespace MauticPlugin\WebAnyOneFormRoutingBundle\Service;

final class Router
{
    public function resolve(array $config, array $fields, array $catalogue, array $results) : array
    {
        $fallback = $config['fallback'];
        $contact = $config['contact'];
        try {
            $alias = Catalogue::resolve($contact, $fields);
        } catch (\InvalidArgumentException $e) {
            return [$fallback, 'broken_mapping', null];
        }
        if ($alias !== ($config['submission_alias'] ?? '') || !isset($catalogue[$contact])) {
            return [$fallback, 'broken_mapping', null];
        }
        $value = $results[$alias] ?? null;
        if (null === $value || '' === $value) {
            return [$fallback, 'empty', null];
        }
        if (!is_string($value)) {
            return [$fallback, 'unexpected_type', null];
        }
        // Decode only when the native branch encodes results.
        $value = SubmissionValue::text($value);
        $key = Catalogue::key($value);
        if (!isset(Catalogue::options($catalogue[$contact])[$key])) {
            return [$fallback, 'unknown', null];
        }
        $rule = $config['banks'][Catalogue::key($contact)]['rows'][$key] ?? [];
        return empty($rule['to']) ? [$fallback, 'unconfigured', $value] : [$rule, 'rule', $value];
    }
}
