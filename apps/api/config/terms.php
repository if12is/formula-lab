<?php

return [
    /*
    | The terms-of-use gate (S-16). When on, every page asks for acceptance of the current terms first.
    | The version is bumped whenever the wording in lang/<locale>/terms.php changes; a person who accepted an older
    | version (or older wording: the text hash is compared too) is asked again.
    */
    'enforce' => (bool) env('TERMS_GATE_ENFORCED', true),

    /* Version label of the wording in lang/<locale>/terms.php. */
    'version' => '2026-11-draft-1',

    /*
    | True while the wording is the project's placeholder draft, not text supplied by the owner or a lawyer (HUMAN-TODO S-16).
    | The screen shows a clear "draft" notice while this is true. Set it to false only together with the final wording and a new version.
    */
    'is_draft' => true,

    /* Routes that stay open without acceptance: the terms screen itself, the preference switches and the health check. */
    'open_routes' => ['terms.show', 'terms.accept', 'settings.theme', 'settings.nav-layout', 'settings.locale', 'settings.display-currency'],
];
