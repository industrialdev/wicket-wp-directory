<?php

declare(strict_types=1);

// Script dependencies for index.js. WordPress reads this file when it registers
// the block's editorScript.
return [
    'dependencies' => ['wp-block-editor', 'wp-blocks', 'wp-element', 'wp-server-side-render'],
    'version'      => WICKET_DIRECTORY_VERSION,
];
