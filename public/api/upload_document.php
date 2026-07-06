<?php
declare(strict_types=1);
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

$pdo = getDB();
$prospectId = (int)($_POST['prospect_id'] ?? 0);
$docType = trim((string)($_POST['document_type'] ?? ''));

$allowedTypes = ['sale_deed','previous_sale_deed','mutation','property_tax_receipt','approved_map','patta'];
if (!$prospectId) jsonErr('prospect_id required');
if (!in_array($docType, $allowedTypes)) jsonErr('Invalid document_type');
if (empty($_FILES['file'])) jsonErr('No file uploaded');

$f = $_FILES['file'];
if ($f['error'] !== UPLOAD_ERR_OK) jsonErr('Upload error code ' . $f['error']);
if ($f['size'] > 25 * 1024 * 1024) jsonErr('File too large (max 25MB)');

$ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['pdf','jpg','jpeg','png'])) jsonErr('Only PDF/JPG/PNG allowed');

// Verify prospect exists
$chk = $pdo->prepare("SELECT id FROM prospects WHERE id=?");
$chk->execute([$prospectId]);
if (!$chk->fetch()) jsonErr('Prospect not found', 404);

$dir = '/var/www/idbi-prospect-assist/uploads/legal/' . $prospectId;
if (!is_dir($dir)) mkdir($dir, 0755, true);
$fname = $docType . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$path = $dir . '/' . $fname;

if (!move_uploaded_file($f['tmp_name'], $path)) jsonErr('Failed to save file', 500);

// Upsert into property_documents with file_path (source = uploaded)
$pdo->prepare("DELETE FROM property_documents WHERE prospect_id=? AND document_type=?")->execute([$prospectId, $docType]);
$pdo->prepare("INSERT INTO property_documents (prospect_id, document_type, file_path, status) VALUES (?, ?, ?, 'received')")
    ->execute([$prospectId, $docType, $path]);

jsonOk([
    'document_type' => $docType,
    'stored' => true,
    'file_name' => $fname,
    'ai_classification' => 'OCR document classification & data extraction — integration-ready (production: auto-extract owner name, property address, registration date)'
]);
