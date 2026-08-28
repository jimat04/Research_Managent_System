<?php
/**
 * RMS Root Index - Redirects to public/
 *
 * This file serves as the entry point and redirects all traffic
 * to the organized public/ directory structure.
 */

// Redirect to public directory
header('Location: public/index.php');
exit();
