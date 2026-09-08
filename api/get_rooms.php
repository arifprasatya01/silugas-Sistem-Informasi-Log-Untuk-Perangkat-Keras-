<?php
// api/get_rooms.php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

header('Content-Type: application/json');

$building_id = cleanInt($_GET['building_id'] ?? 0);
if (!$building_id) { echo json_encode([]); exit; }

$db = getDB();
$stmt = $db->prepare("SELECT id, name FROM rooms WHERE building_id = ? ORDER BY name");
$stmt->execute([$building_id]);
echo json_encode($stmt->fetchAll());
