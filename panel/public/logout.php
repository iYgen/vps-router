<?php

require __DIR__ . '/../src/bootstrap.php';

\App\Auth::logout();
header('Location: /login.php');
