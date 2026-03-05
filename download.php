<?php
declare(strict_types=1);
require __DIR__ . '/db.php';

$cid = (int)($_GET['campaign_id'] ?? 0);
if ($cid <= 0) { http_response_code(400); exit('Invalid campaign'); }

$campaign = $pdo->prepare("SELECT id, name FROM campaigns WHERE id=:id AND deleted_at IS NULL");
$campaign->execute([':id' => $cid]);
$c = $campaign->fetch(PDO::FETCH_ASSOC);
if (!$c) { http_response_code(404); exit('Campaign not found'); }

$stmt = $pdo->prepare("
  SELECT business_name, phone, status, attempts, last_result, created_at
  FROM leads
  WHERE campaign_id = :cid AND deleted_at IS NULL
  ORDER BY id
");
$stmt->execute([':cid' => $cid]);

$fname = preg_replace('/[^a-zA-Z0-9_\-]+/', '_', (string)$c['name']);
if ($fname === '' || $fname === null) $fname = 'campaign';
$fname = $fname . '_' . $cid . '.csv';

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="'.$fname.'"');

$out = fopen('php://output', 'w');
fputcsv($out, ['Business Name','Phone','Status','Attempts','Last Result','Created At']);

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
  fputcsv($out, $row);
}
fclose($out);
exit;
