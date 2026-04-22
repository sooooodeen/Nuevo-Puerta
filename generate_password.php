<?php
$newPassword = "carlo123"; // <-- change this
echo password_hash($newPassword, PASSWORD_BCRYPT);
