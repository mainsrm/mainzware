<?php
declare(strict_types=1);

namespace MainzWorld\Controllers;

use MainzWorld\Data\UserPreferences;
use MainzWorld\Support\Auth;

final class PreferencesController
{
    public function show(): void
    {
        header('Content-Type: application/json');
        $user = Auth::requireLogin();
        if ($user === null) {
            return;
        }

        echo json_encode(UserPreferences::findForUser((int) $user['id']), JSON_THROW_ON_ERROR);
    }

    public function update(): void
    {
        header('Content-Type: application/json');
        $user = Auth::requireLogin();
        if ($user === null) {
            return;
        }

        $body = json_decode(file_get_contents('php://input') ?: '{}', true);
        $colorMode = $body['color_mode'] ?? null;
        if (!in_array($colorMode, ['light', 'dark'], true)) {
            http_response_code(400);
            echo json_encode(['error' => 'Color mode must be light or dark.']);
            return;
        }

        echo json_encode(
            UserPreferences::setColorMode((int) $user['id'], $colorMode),
            JSON_THROW_ON_ERROR,
        );
    }
}
