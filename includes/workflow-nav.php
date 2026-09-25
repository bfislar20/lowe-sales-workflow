<?php
declare(strict_types=1);

/**
 * Shared navigation helpers for internal Lowe workflow pages.
 *
 * The helper intentionally does not impose page styling. Each page supplies
 * its existing button/link class so navigation can be standardized without
 * changing the page's visual design.
 */
function workflow_url(string $file='salesworkflow.php'): string {
    return $file;
}

function workflow_back_link(string $class='btn', string $label='← Sales Workflow'): string {
    return '<a class="'.htmlspecialchars($class,ENT_QUOTES,'UTF-8').'" href="'.
        htmlspecialchars(workflow_url(),ENT_QUOTES,'UTF-8').'">'.
        htmlspecialchars($label,ENT_QUOTES,'UTF-8').'</a>';
}
