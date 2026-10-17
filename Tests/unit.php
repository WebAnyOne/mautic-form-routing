<?php

// No database, kernel, transport or network. Supply a native Mautic vendor directory.
if ('cli' !== PHP_SAPI) {
    exit(1);
}
require ($argv[1] ?? '') . '/autoload.php';
spl_autoload_register(static function ($class) {
    $prefix = 'MauticPlugin\\WebAnyOneFormRoutingBundle\\';
    if (strpos($class, $prefix) === 0) {
        require dirname(__DIR__) . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});
use MauticPlugin\WebAnyOneFormRoutingBundle\Service\Catalogue;
use MauticPlugin\WebAnyOneFormRoutingBundle\Service\Router;
use MauticPlugin\WebAnyOneFormRoutingBundle\Service\Composer;
use MauticPlugin\WebAnyOneFormRoutingBundle\Service\Recipients;
$n = 0;
function ok($value)
{
    global $n;
    ++$n;
    if (!$value) {
        throw new RuntimeException('Assertion ' . $n);
    }
}
$recipient = new Recipients(\Symfony\Component\Validator\Validation::createValidator());
$group = $recipient->normalize(['to' => 'A@example.invalid; b@example.invalid', 'cc' => 'a@example.invalid;c@example.invalid', 'bcc' => 'C@example.invalid;d@example.invalid'], true);
ok($group === ['to' => 'A@example.invalid, b@example.invalid', 'cc' => 'c@example.invalid', 'bcc' => 'd@example.invalid']);
foreach (['bad', 'a@example.invalid;bad', "a@example.invalid\nBcc:b@example.invalid", '{formfield=email}'] as $input) {
    try {
        $recipient->normalize(['to' => $input]);
        ok(false);
    } catch (InvalidArgumentException $e) {
        ok(true);
    }
}
foreach ([[], ['cc' => 'c@example.invalid']] as $input) {
    try {
        $recipient->normalize($input, true);
        ok(false);
    } catch (InvalidArgumentException $e) {
        ok(true);
    }
}
$fields = [['alias' => 'agency', 'type' => 'select', 'mappedObject' => 'contact', 'mappedField' => 'agency', 'properties' => ['multiple' => false]]];
$catalogue = ['agency' => ['properties' => ['list' => [['value' => '01', 'label' => 'Zero'], ['value' => '1', 'label' => 'One'], ['value' => 'empty', 'label' => 'Empty']]]]];
$config = ['contact' => 'agency', 'submission_alias' => 'agency', 'fallback' => ['to' => 'backup@example.invalid'], 'banks' => [Catalogue::key('agency') => ['rows' => [Catalogue::key('01') => ['to' => 'zero@example.invalid'], Catalogue::key('1') => ['to' => 'one@example.invalid']]]]];
$router = new Router();
ok($router->resolve($config, $fields, $catalogue, ['agency' => '01'])[0]['to'] === 'zero@example.invalid');
ok($router->resolve($config, $fields, $catalogue, ['agency' => '1'])[0]['to'] === 'one@example.invalid');
foreach ([null, '', [], ['1'], 1, 'unknown', 'empty'] as $value) {
    ok($router->resolve($config, $fields, $catalogue, ['agency' => $value])[0]['to'] === 'backup@example.invalid');
}
ok($router->resolve($config, [], $catalogue, [])[1] === 'broken_mapping');
$duplicate = [$fields[0], $fields[0]];
ok($router->resolve($config, $duplicate, $catalogue, ['agency' => '1'])[1] === 'broken_mapping');
$legacy = [['alias' => 'agency', 'type' => 'select', 'leadField' => 'agency', 'properties' => []]];
ok(Catalogue::resolve('agency', $legacy) === 'agency');
$company = [['alias' => 'agency', 'type' => 'select', 'mappedObject' => 'company', 'mappedField' => 'agency']];
ok($router->resolve($config, $company, $catalogue, [])[1] === 'broken_mapping');
$multi = $fields;
$multi[0]['properties']['multiple'] = true;
ok($router->resolve($config, $multi, $catalogue, [])[1] === 'broken_mapping');
$fields[] = ['alias' => 'message', 'label' => 'Message', 'type' => 'textarea', 'saveResult' => true];
$fields[] = ['alias' => 'email', 'label' => 'Email', 'type' => 'email', 'mappedObject' => 'contact', 'mappedField' => 'email', 'saveResult' => true];
$config += ['subject' => '{formfield=agency}', 'message' => '<p>{formfield=message}</p>'];
$m = (new Composer())->compose($config, $fields, ['agency' => "1\r\nBcc:evil", 'message' => "Été\n&lt;img src=x onerror=alert(1)&gt; {formfield=email}", 'email' => 'current@example.invalid']);
ok(strpos($m['subject'], "\n") === false);
ok(strpos($m['body'], '<img') === false);
ok(strpos($m['body'], '&lt;img') !== false);
ok(strpos($m['body'], '<br') !== false);
ok(strpos($m['body'], '{formfield=email}') === false);
ok($m['reply'] === 'current@example.invalid');
foreach (['password', 'file', 'hidden', 'button'] as $type) {
    ok(!Composer::allowed(['type' => $type, 'saveResult' => true]));
}
ok(!Composer::allowed(['type' => 'text', 'saveResult' => false]));
echo json_encode(['php' => PHP_VERSION, 'assertions' => $n, 'status' => 'PASS']) . "\n";
