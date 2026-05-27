<?php

namespace WHMCS\Module\Addon\EkdosiBridge\Admin;

/**
 * Mirrors the legacy prepare_for_ekdosi dispatcher: routes the
 * `action` query param to a public method on Controller.
 */
class AdminDispatcher
{
    public function dispatch(string $action, array $parameters): string
    {
        if ($action === '') {
            $action = 'index';
        }
        $controller = new Controller();
        if (! is_callable([$controller, $action])) {
            return '<div class="alert alert-warning">Unknown action. <a href="'
                .htmlspecialchars($parameters['modulelink'] ?? '').'">Back</a></div>';
        }
        return $controller->$action($parameters);
    }
}
