<?php
if (PHP_SAPI !== 'cli') { exit(1); }
$version = $argv[1] ?? '';
$root = sys_get_temp_dir().'/webanyone-native-comparison/results/'.$version;
$with = json_decode(file_get_contents($root.'/with.json'), true);
$without = json_decode(file_get_contents($root.'/without.json'), true);
if (($with['status'] ?? '') !== 'PASS' || ($without['status'] ?? '') !== 'PASS') { throw new RuntimeException('Both kernel tests must pass first'); }
$checks = ['native_definition', 'native_message', 'native_tokens', 'native_html_sha256', 'empty_session_message', 'empty_session_tokens'];
foreach ($checks as $key) {
    if ($with[$key] !== $without[$key]) { throw new RuntimeException('Native differs with/without bundle: '.$key); }
}
if ($with['native_html_sha256'] !== $with['after_custom_html_sha256']) { throw new RuntimeException('Custom form affected the native renderer'); }
$report = ['mautic'=>$version, 'assertions'=>count($checks)+1, 'status'=>'PASS', 'native_html_identical'=>true, 'native_html_sha256'=>$with['native_html_sha256']];
file_put_contents($root.'/comparison.json', json_encode($report, JSON_PRETTY_PRINT)."\n");
echo json_encode($report)."\n";
