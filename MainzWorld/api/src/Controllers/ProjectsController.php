<?php
declare(strict_types=1);

namespace MainzWorld\Controllers;

use MainzWorld\Data\Projects;

final class ProjectsController
{
    public function index(): void
    {
        header('Content-Type: application/json');
        echo json_encode(Projects::all(), JSON_THROW_ON_ERROR);
    }
}
