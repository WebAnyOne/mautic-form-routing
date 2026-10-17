<?php
// Read-only native-editor regression; separate kernels really include/exclude this bundle.
if ('cli' !== PHP_SAPI) { exit(1); }
$lab = realpath($argv[1] ?? '');
$mode = $argv[2] ?? 'with';
if (!$lab || !is_file($lab.'/autoload.php') || !in_array($mode, ['with', 'without'], true)) {
    throw new RuntimeException('Usage: php native-compatibility.php /disposable/mautic with|without');
}
chdir($lab);
require $lab.'/autoload.php';
$_SERVER['MAUTIC_TABLE_PREFIX'] = '';
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'prod';
class WebAnyOneComparisonKernel extends AppKernel
{
    private $lab;
    private $mode;
    public function __construct(string $lab, string $mode)
    {
        $this->lab = $lab;
        $this->mode = $mode;
        parent::__construct('prod', false);
    }
    public function getProjectDir(): string { return $this->lab; }
    public function getRootDir(): string { return $this->lab.'/app'; }
    public function getApplicationDir(): string { return $this->lab; }
    public function getCacheDir(): string { return sys_get_temp_dir().'/webanyone-native-comparison/'.basename($this->lab).'/'.$this->mode.'/cache'; }
    public function getLogDir(): string { return sys_get_temp_dir().'/webanyone-native-comparison/'.basename($this->lab).'/'.$this->mode.'/logs'; }
    public function registerBundles(): array
    {
        return array_values(array_filter(parent::registerBundles(), function ($bundle) {
            return $this->mode !== 'without' || $bundle->getName() !== 'WebAnyOneFormRoutingBundle';
        }));
    }
}
$kernel = new WebAnyOneComparisonKernel($lab, $mode);
$kernel->boot();
$c = $kernel->getContainer();
$db = $c->get('doctrine.orm.entity_manager')->getConnection();
if (strpos($db->getDatabase(), 'apimonitor_lab_') !== 0) { throw new RuntimeException('Disposable apimonitor_lab_* only'); }
$n = 0;
function checkNative($condition, $message)
{
    global $n;
    if (!$condition) { throw new RuntimeException($message); }
    ++$n;
}
function nativeProperty($object, $name)
{
    $r = new ReflectionObject($object);
    while (!$r->hasProperty($name)) { $r = $r->getParentClass(); }
    $p = $r->getProperty($name);
    $p->setAccessible(true);
    return $p->getValue($object);
}
use Mautic\FormBundle\Form\Type\ActionType;
use Mautic\FormBundle\Form\Type\SubmitActionEmailType;
use Symfony\Component\HttpFoundation\Request;
$session = new Symfony\Component\HttpFoundation\Session\Session(new Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage());
$request = Request::create('/s/forms/edit/987654');
$request->setSession($session);
$c->get('request_stack')->push($request);
if ($c->has('session')) { $c->set('session', $session); }
$translator = $c->get('translator');
$translator->setLocale('fr');
$factory = $c->has('form.factory') ? $c->get('form.factory') : nativeProperty($c->get(Mautic\CoreBundle\Controller\FormController::class), 'formFactory');
$helper = $c->get('mautic.helper.mailer');
$legacy = version_compare(MAUTIC_VERSION, '5.0', '<');
if ($legacy) {
    $engine = $c->get('mautic.helper.templating')->getTemplating();
    if ($engine instanceof Symfony\Bundle\FrameworkBundle\Templating\DelegatingEngine) { $engine = $engine->getEngine('MauticFormBundle:Builder:action.html.php'); }
} else {
    $twig = $c->has('twig') ? $c->get('twig') : nativeProperty($helper, 'twig');
    $twig->enableAutoReload();
}
$components = $c->get('mautic.form.model.form')->getCustomComponents();
$nativeSettings = $components['actions']['form.email'];
checkNative($nativeSettings['formType'] === SubmitActionEmailType::class, 'Native action still resolves its own type');
checkNative(isset($components['actions']['form.routing_results']) === ($mode === 'with'), 'Bundle actually included/excluded');
checkNative($c->has('webanyone.formrouting.subscriber') === ($mode === 'with'), 'Services absent from baseline container');
$formId = '987654';
$fields = [
    'a'=>['id'=>'a','alias'=>'agency','label'=>'Agence','type'=>'select','mappedObject'=>'contact','mappedField'=>'agency','leadField'=>'agency','properties'=>['multiple'=>false],'saveResult'=>true],
    'e'=>['id'=>'e','alias'=>'email','label'=>'Courriel','type'=>'email','mappedObject'=>'contact','mappedField'=>'email','leadField'=>'email','properties'=>[],'saveResult'=>true],
    'm'=>['id'=>'m','alias'=>'message','label'=>'Demande','type'=>'textarea','properties'=>[],'saveResult'=>true],
    'b'=>['id'=>'b','alias'=>'submit','label'=>'Envoyer','type'=>'button','properties'=>[],'saveResult'=>false],
];
$setFields = function (array $values) use ($session, $formId) {
    $session->set('mautic.form.'.$formId.'.fields.modified', $values);
    $session->set('mautic.form.'.$formId.'.fields.deleted', []);
};
$render = function ($settings, $id, array $properties = []) use ($factory, $legacy, &$engine, &$twig) {
    $form = $factory->create(ActionType::class, ['type'=>isset($settings['formType']) && $settings['formType'] === SubmitActionEmailType::class ? 'form.email' : 'form.routing_results', 'properties'=>$properties], ['formId'=>$id, 'settings'=>$settings, 'csrf_protection'=>false]);
    $view = $form->createView();
    if ($legacy) {
        $engine->get('form')->setTheme($view, [$settings['formTheme'], 'MauticCoreBundle:FormTheme\\Custom']);
        $html = $engine->render('MauticFormBundle:Builder:action.html.php', ['form'=>$view, 'actionHeader'=>'Compatibilité des actions']);
    } else {
        $html = $twig->render('@MauticForm/Builder/action.html.twig', ['form'=>$view, 'formTheme'=>$settings['formTheme'], 'actionHeader'=>'Compatibilité des actions']);
    }
    return ['message'=>$form->get('properties')->get('message')->getData(), 'tokens'=>$view['properties']->vars['formFields'] ?? $view['properties']->vars['routingTokens'] ?? [], 'html'=>$html];
};
$setFields($fields);
$initialSession = $session->all();
$initial = $render($nativeSettings, $formId);
checkNative(strpos($initial['message'], '{formfield=agency}') !== false && strpos($initial['message'], '{formfield=message}') !== false, 'Native default message populated');
checkNative(count($initial['tokens']) === 3, 'Native picker has three useful fields');
checkNative(strpos($initial['html'], 'formfield=agency') !== false && strpos($initial['html'], 'Demande') !== false, 'Native rendered picker populated');
$after = $initial;
if ($mode === 'with') {
    $custom = $render($components['actions']['form.routing_results'], $formId);
    checkNative(count($custom['tokens']) === 3, 'Custom picker populated');
    checkNative(strpos($custom['html'], 'webanyoneFormRoutingTokens') !== false, 'Custom picker uses its own DOM id');
    checkNative(strpos($custom['html'], 'id="formFieldTokens"') === false && strpos($custom['html'], 'id="emailMessage"') === false, 'Custom theme does not reuse native DOM ids');
    checkNative($session->all() === $initialSession, 'Opening custom action does not change native session fields');
    $after = $render($nativeSettings, $formId);
    checkNative($after['message'] === $initial['message'] && $after['tokens'] === $initial['tokens'], 'Opening native after custom preserves message and tokens');
    checkNative($after['html'] === $initial['html'], 'Native rendered HTML unchanged after custom theme');
    $render($components['actions']['form.routing_results'], $formId);
    $third = $render($nativeSettings, $formId);
    checkNative($third === $after, 'Repeated native/custom switches are stable');
}
$saved = $render($nativeSettings, $formId, ['subject'=>'Sujet conservé', 'message'=>'<p>Modèle natif existant {formfield=email}</p>']);
checkNative($saved['message'] === '<p>Modèle natif existant {formfield=email}</p>', 'Existing native message remains unchanged');
$blankSaved = $render($nativeSettings, $formId, ['subject'=>'Sujet conservé', 'message'=>'']);
checkNative($blankSaved['message'] === '' && count($blankSaved['tokens']) === 3, 'Native explicitly empty saved message is not overwritten');
$wrongId = $render($nativeSettings, 'different-form-id');
checkNative($wrongId['message'] === '' && $wrongId['tokens'] === [], 'A mismatched native form id reproduces both empty areas');
$setFields([]);
$empty = $render($nativeSettings, $formId);
checkNative($empty['message'] === '' && $empty['tokens'] === [], 'Empty native field session reproduces both empty areas');
$setFields($fields);
$session->set('mautic.form.'.$formId.'.fields.deleted', ['m']);
$deleted = $render($nativeSettings, $formId);
checkNative(count($deleted['tokens']) === 2 && strpos($deleted['message'], '{formfield=message}') === false, 'Native deleted-field filtering preserved');
$out = sys_get_temp_dir().'/webanyone-native-comparison/results/'.MAUTIC_VERSION;
if (!is_dir($out)) { mkdir($out, 0700, true); }
foreach (['initial'=>$initial, 'after_custom'=>$after, 'empty_session'=>$empty] as $label=>$result) { file_put_contents($out.'/'.$mode.'-'.$label.'.html', $result['html']); }
$report = ['mautic'=>MAUTIC_VERSION, 'php'=>PHP_VERSION, 'plugin'=>$mode, 'assertions'=>$n, 'status'=>'PASS', 'native_definition'=>$nativeSettings, 'native_message'=>$initial['message'], 'native_tokens'=>$initial['tokens'], 'native_html_sha256'=>hash('sha256', $initial['html']), 'after_custom_html_sha256'=>hash('sha256', $after['html']), 'empty_session_message'=>$empty['message'], 'empty_session_tokens'=>$empty['tokens']];
file_put_contents($out.'/'.$mode.'.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
echo json_encode(['mautic'=>MAUTIC_VERSION, 'plugin'=>$mode, 'assertions'=>$n, 'status'=>'PASS'])."\n";
