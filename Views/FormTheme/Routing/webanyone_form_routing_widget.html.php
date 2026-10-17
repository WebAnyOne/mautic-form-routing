<?php
$escape = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
echo $view['form']->errors($form);
?>
<div class="row">
    <div class="col-sm-8"><?php echo $view['form']->row($form['subject']); ?></div>
    <div class="col-sm-4"><?php echo $view['form']->row($form['set_replyto']); ?></div>
</div>
<div class="row">
    <div class="col-sm-8" id="webanyoneFormRoutingMessage">
        <?php echo $view['form']->row($form['message']); ?>
    </div>
    <div class="col-sm-4">
        <label class="control-label"><?php echo $escape($view['translator']->trans('webanyone.formrouting.tokens')); ?></label>
        <div id="webanyoneFormRoutingTokens" class="list-group" style="max-height: 250px; overflow-y: auto;">
            <?php foreach ($routingTokens as $token => $label):
                $insert = "Mautic.insertTextInEditor(mQuery('#formaction_properties_message'), ".json_encode($token)."); return false;";
            ?>
                <a class="list-group-item ellipsis" href="#" onclick="<?php echo $escape($insert); ?>"><?php echo $escape($label); ?></a>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php
 echo $view['form']->row($form['contact']);
 echo '<p class="help-block">'.$escape($view['translator']->trans('webanyone.formrouting.help')).'</p>';
 echo $view['form']->row($form['fallback']);
 foreach ($form['banks'] as $bank):
?>
    <details<?php echo $bank->vars['name'] === $routingSelectedKey ? ' open' : ''; ?>>
        <summary><?php echo $escape($bank->vars['label']); ?></summary>
        <?php foreach ($bank['rows'] as $row): ?>
            <div class="panel panel-default">
                <div class="panel-heading"><?php echo $escape($row->vars['label']); ?></div>
                <div class="panel-body">
                    <div class="row">
                        <?php foreach (['to', 'cc', 'bcc'] as $kind): ?>
                            <div class="col-sm-4"><?php echo $view['form']->row($row[$kind]); ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </details>
<?php endforeach; ?>
