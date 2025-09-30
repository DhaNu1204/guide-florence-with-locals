<?php
// Replace this hash with the one from your database for Dhanu
$hash = '$2y$10$UTrxVO2HhmgQW.MLA13sI.Z17.qklLHLuKnDOzAh49639geCVhbLy';

$password = 'test1234';

if (password_verify($password, $hash)) {
    echo 'Password is correct!';
} else {
    echo 'Password is NOT correct!';
} 