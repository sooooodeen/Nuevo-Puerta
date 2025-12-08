<?php
session_start();
unset($_SESSION['agent_id']);
unset($_SESSION['user']);
unset($_SESSION['role']);
unset($_SESSION['first_name']);
unset($_SESSION['csrf_token']);
session_unset();
session_destroy();
header("Location: Login/login.php");
exit();
?>