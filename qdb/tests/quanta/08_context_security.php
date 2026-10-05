<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

[$env] = quanta_env();

$_REQUEST['context'] = 'plain context';
eq(
    \Quanta\Common\QtagFactory::transformCodeTags($env, '[CONTEXT]'),
    'plain context',
    'CONTEXT preserves ordinary request text'
);

$env->setData('CONTEXT_TEST_SECRET', 'context-secret');

$_REQUEST['context'] = '[ENV:CONTEXT_TEST_SECRET]';
$square = \Quanta\Common\QtagFactory::transformCodeTags($env, '[CONTEXT]');
eq(
    $square,
    '&lbrack;ENV&colon;CONTEXT_TEST_SECRET&rbrack;',
    'CONTEXT keeps square-bracket Qtag markup inert'
);
ok(
    strpos($square, 'context-secret') === FALSE,
    'square-bracket request data is not evaluated as a nested Qtag'
);

$_REQUEST['context'] = '{ENV:CONTEXT_TEST_SECRET}';
$curly = \Quanta\Common\QtagFactory::transformCodeTags($env, '[CONTEXT]');
eq(
    $curly,
    '&lbrace;ENV&colon;CONTEXT_TEST_SECRET&rbrace;',
    'CONTEXT keeps curly-brace Qtag markup inert'
);
ok(
    strpos($curly, 'context-secret') === FALSE,
    'curly-brace request data is not evaluated as a nested Qtag'
);

$_REQUEST['context'] = '<img src=x onerror="alert(1)">';
eq(
    \Quanta\Common\QtagFactory::transformCodeTags($env, '[CONTEXT]'),
    '&lt;img src=x onerror=&quot;alert(1)&quot;&gt;',
    'CONTEXT HTML-escapes request markup'
);

$_REQUEST['context'] = array('not', 'scalar');
eq(
    \Quanta\Common\QtagFactory::transformCodeTags($env, '[CONTEXT]'),
    '',
    'CONTEXT rejects structured request values'
);

unset($_REQUEST['context']);
eq(
    \Quanta\Common\QtagFactory::transformCodeTags($env, '[CONTEXT]'),
    '',
    'CONTEXT handles a missing request value without a notice'
);

finish();
