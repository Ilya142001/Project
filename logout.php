<?php
session_start();
session_unset();
session_destroy();
header("Location: index.html?message=" . urlencode("Вы успешно вышли из системы") . "&type=success");
exit;