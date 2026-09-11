<?php
declare(strict_types=1);

namespace MainzWorld\Controllers;

use MainzWorld\Data\CmsContent;
use MainzWorld\Support\Auth;

final class ContentController
{
    private const ALLOWED_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    private const MAX_IMAGE_BYTES = 8 * 1024 * 1024; // 8 MB
    private const ALLOWED_PAGES = ['Home', 'Page_01', 'Page_02', 'Page_03'];

    public function index(): void
    {
        header('Content-Type: application/json');

        $page = $_GET['page'] ?? 'Home';
        if (!in_array($page, self::ALLOWED_PAGES, true)) {
            http_response_code(400);
            echo json_encode(['error' => 'Unknown page.']);
            return;
        }

        echo json_encode(CmsContent::forPage($page), JSON_THROW_ON_ERROR);
    }

    public function create(): void
    {
        header('Content-Type: application/json');

        $user = Auth::requireLogin();
        if ($user === null) {
            return;
        }

        $page = $_POST['page'] ?? '';
        $title = trim((string) ($_POST['title'] ?? ''));
        if (!in_array($page, self::ALLOWED_PAGES, true) || $title === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Fields "page" (valid page name) and "title" are required.']);
            return;
        }

        $imageUrl = null;
        if (!empty($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            try {
                $imageUrl = $this->storeImage($_FILES['image'], $page);
            } catch (\RuntimeException $e) {
                http_response_code(400);
                echo json_encode(['error' => $e->getMessage()]);
                return;
            }
        }

        $id = CmsContent::create([
            'page' => $page,
            'type' => $_POST['type'] ?? 'article',
            'title' => $title,
            'details' => $_POST['details'] ?? null,
            'image_url' => $imageUrl,
            'location' => $_POST['location'] ?? null,
            'event_time' => $_POST['event_time'] ?: null,
        ], (int) $user['id']);

        echo json_encode(['id' => $id, 'image_url' => $imageUrl], JSON_THROW_ON_ERROR);
    }

    private function storeImage(array $file, string $page): string
    {
        if ($file['size'] > self::MAX_IMAGE_BYTES) {
            throw new \RuntimeException('Image exceeds the 8MB limit.');
        }

        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_IMAGE_EXTENSIONS, true)) {
            throw new \RuntimeException('Only jpg, jpeg, png, webp, and gif images are supported.');
        }

        // Verify it's actually an image (not just a renamed file) before storing it.
        if (@getimagesize($file['tmp_name']) === false) {
            throw new \RuntimeException('Uploaded file is not a valid image.');
        }

        $dir = __DIR__ . '/../../public/uploads/' . $page;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create upload directory.');
        }

        // Random filename — never trust the client-provided name for the on-disk path.
        $filename = bin2hex(random_bytes(16)) . '.' . $extension;
        $destination = $dir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new \RuntimeException('Could not save uploaded image.');
        }

        return '/uploads/' . $page . '/' . $filename;
    }
}
