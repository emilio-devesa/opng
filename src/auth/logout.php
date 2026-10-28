<?php
require("../includes/session.php");
session_start_secured();
session_destroy();
header("Location: ../index.php");
exit;
