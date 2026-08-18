<?php
require 'vendor/autoload.php';

use Kreait\Firebase\Factory;

// Use the exact absolute path from your earlier confirmation
$credentialsPath = 'C:\\firebase\\firebase-credentials.json';

echo "Checking file: $credentialsPath\n";

if (!file_exists($credentialsPath)) {
    die("ERROR: File not found at that path.\n");
}
echo "File exists.\n";

if (!is_readable($credentialsPath)) {
    die("ERROR: File is not readable (permissions issue).\n");
}
echo "File is readable.\n";

// Try to load the JSON content to verify it's valid
$content = file_get_contents($credentialsPath);
$json = json_decode($content);
if ($json === null) {
    die("ERROR: JSON is invalid. Last error: " . json_last_error_msg() . "\n");
}
echo "JSON is valid.\n";

try {
    $factory = (new Factory)->withServiceAccount($credentialsPath);
    echo "Factory created.\n";
    
    $firestore = $factory->createFirestore();
    echo "Firestore client created successfully!\n";
    
    // Optional: try a simple query
    $database = $firestore->database();
    echo "Database object obtained. Firebase is ready!\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}