<?php

namespace MauticPlugin\WebAnyOneFormRoutingBundle\Service;

use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Validator\ValidatorInterface;
final class Recipients
{
    private $validator;
    public function __construct(ValidatorInterface $validator)
    {
        $this->validator = $validator;
    }
    public function valid(string $email) : bool
    {
        return !preg_match('/[\\r\\n{}<>]/', $email) && 0 === count($this->validator->validate($email, new Email(['mode' => 'strict'])));
    }
    public function normalize(array $group, bool $required = false) : array
    {
        $out = ['to' => '', 'cc' => '', 'bcc' => ''];
        $seen = [];
        foreach ($out as $kind => $_) {
            $items = [];
            $input = $group[$kind] ?? '';
            if (!is_string($input)) {
                throw new \InvalidArgumentException('webanyone.formrouting.invalid_address');
            }
            foreach (preg_split('/[,;]/', $input) as $address) {
                $address = trim($address);
                if ('' === $address) {
                    continue;
                }
                if (!$this->valid($address)) {
                    throw new \InvalidArgumentException('webanyone.formrouting.invalid_address');
                }
                $key = strtolower($address);
                if (!isset($seen[$key])) {
                    $items[] = $address;
                    $seen[$key] = true;
                }
            }
            $out[$kind] = implode(', ', $items);
        }
        if ('' === $out['to'] && ($required || '' !== $out['cc'] || '' !== $out['bcc'])) {
            throw new \InvalidArgumentException('webanyone.formrouting.to_required');
        }
        return $out;
    }
    public static function addresses(string $input) : array
    {
        return '' === $input ? [] : array_fill_keys(explode(', ', $input), null);
    }
}
