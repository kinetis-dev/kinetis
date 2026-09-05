<?php

declare(strict_types=1);

// Reports what one PHP process does with a form body, so
// FormPairsTest can compare `parse_str()`'s own reading of a body
// against Kinetis' under a real `arg_separator.input`. It runs in a
// subprocess because that setting is PHP_INI_PERDIR: `ini_set()`
// cannot move it, which is the property the separator contract rests
// on, so the only way to exercise another value is to start a process
// with it.
//
// Reads KINETIS_PROBE_BODY, writes one JSON object to stdout.

require dirname(__DIR__, 4) . '/vendor/autoload.php';

use Kinetis\Http\Form\Exception\FormLimitExceededException;
use Kinetis\Http\Form\Exception\FormParserConfigurationException;
use Kinetis\Http\Form\FormLimits;
use Kinetis\Http\Form\UrlEncodedForm;

$body = (string) getenv('KINETIS_PROBE_BODY');

// Suppressed because a body past this process's own max_input_vars is
// one of the cases under test, and the warning parse_str() raises for
// it would land in the JSON this fixture writes.
@parse_str($body, $direct);

$result = [
    'separator' => ini_get('arg_separator.input'),
    'parse_str' => $direct,
    'parsed' => null,
    'refused' => null,
    'overLimit' => null,
];

try {
    $result['parsed'] = UrlEncodedForm::parse($body, new FormLimits(FormLimits::DEFAULT_MAX_BODY_BYTES));
} catch (FormParserConfigurationException $e) {
    $result['refused'] = $e->getMessage();
} catch (FormLimitExceededException $e) {
    $result['overLimit'] = $e->getMessage();
}

echo json_encode($result, JSON_THROW_ON_ERROR);
