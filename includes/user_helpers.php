<?php
// Call session_start() at the top of any script using this function
function userHasAccount() {
    return isset($_SESSION['user_id']);
}
