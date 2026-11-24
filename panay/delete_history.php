<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }

/* (Optional) Kung Admin lang ang pwedeng mag-delete, i-uncomment:
if (($_SESSION['role'] ?? '') !== 'Admin') { header("Location: import_history.php"); exit(); }
*/

 $id = (int)($_POST['id'] ?? 0);
if ($id <= 0) { header("Location: import_history.php"); exit(); }

/* 1) Kunin ang filename ng history record */
 $stmt = $conn->prepare("SELECT filename FROM import_history WHERE id = ?");
 $stmt->bind_param("i", $id);
 $stmt->execute();
 $row = $stmt->get_result()->fetch_assoc();

if (!$row) { header("Location: import_history.php"); exit(); }

 $file = $row['filename'];

/* 2) I-delete ang LAHAT ng picklists na galing sa file na ito */
 $del = $conn->prepare("DELETE FROM picklists WHERE imported_file = ?");
 $del->bind_param("s", $file);
 $del->execute();
 $deletedPicklists = $del->affected_rows;

/* 3) I-delete ang history record mismo */
 $del = $conn->prepare("DELETE FROM import_history WHERE id = ?");
 $del->bind_param("i", $id);
 $del->execute();

header("Location: import_history.php?deleted=1&rows=" . $deletedPicklists);
exit();