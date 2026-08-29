<?php

return [
    // Filament emits Alpine expressions that are outside Livewire's CSP-safe
    // evaluator subset. The admin panel receives a narrowly scoped
    // `unsafe-eval` allowance from SecurityHeaders instead.
    'csp_safe' => false,
];
