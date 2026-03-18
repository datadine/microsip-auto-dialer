<?php
session_start();
echo "Session user_id: " . (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'NOT SET') . "<br>";
echo "Session role: " . (isset($_SESSION['role']) ? $_SESSION['role'] : 'NOT SET') . "<br>";
echo "<br><a href='/leads/admin.php'>Go to Admin</a>";
echo "<br><a href='call_reports.php'>Try Call Reports</a>";
?>
