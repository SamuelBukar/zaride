 <?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
$riderId = requireAccount('rider');
$field = $_GET['file'] ?? '';
$column = $field === 'vehicle' ? 'vehicle_photo_path' : ($field === 'plate' ? 'plate_photo_path' : '');

if ($column === '') {
    http_response_code(404);
    exit('File not found.');
}

try {
    $statement = db()->prepare("SELECT {$column} FROM riders WHERE id = ?");
    $statement->execute([$riderId]);
    $filename = $statement->fetchColumn();
} catch (PDOException $exception) {
    error_log('ZaRide private image lookup failed: ' . $exception->getMessage());
    http_response_code(503);
    exit('The private image is temporarily unavailable.');
}

if (!is_string($filename) || !preg_match('/\A[a-f0-9]{48}\.(jpg|png|webp)\z/', $filename)) {
    http_response_code(404);
    exit('File not found.');
}

$path = privateUploadDirectory() . DIRECTORY_SEPARATOR . $filename;
if (!is_file($path) || !is_readable($path)) {
    http_response_code(404);
    exit('File not found.');
}

$mimeTypes = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
$extension = pathinfo($filename, PATHINFO_EXTENSION);
header('Content-Type: ' . $mimeTypes[$extension]);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: inline; filename="' . ($field === 'vehicle' ? 'vehicle-photo' : 'plate-photo') . '.' . $extension . '"');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
readfile($path);
