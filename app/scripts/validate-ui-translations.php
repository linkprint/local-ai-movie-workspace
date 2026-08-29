#!/usr/bin/env php
<?php

use App\Support\UiTranslationGuard;

require_once __DIR__.'/../app/Support/UiTranslationGuard.php';

$applicationRoot = dirname(__DIR__);
$languageRoot = $argv[1] ?? $applicationRoot.'/lang';
$viewRoot = $argv[2] ?? $applicationRoot.'/resources/views';
$baselinePath = $argv[3] ?? $applicationRoot.'/lang/ui-required-keys.txt';

$errors = (new UiTranslationGuard)->validate($languageRoot, $viewRoot, $baselinePath);

if ($errors !== []) {
    fwrite(STDERR, "UI translation guard failed:\n- ".implode("\n- ", $errors)."\n");
    exit(1);
}

fwrite(STDOUT, "UI translation guard: PASS\n");
