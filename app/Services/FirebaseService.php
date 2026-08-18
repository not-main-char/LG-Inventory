<?php

namespace App\Services;

use Kreait\Firebase\Factory;

class FirebaseService
{
    protected $factory;

    public function __construct()
    {
        // Set the environment variable directly for this request
        putenv('GOOGLE_APPLICATION_CREDENTIALS=C:\firebase\firebase-credentials.json');

        $this->factory = (new Factory)
            ->withDatabaseUri(env('FIREBASE_DATABASE_URL'));
    }

    public function getFirestore()
    {
        return $this->factory->createFirestore()->database();
    }

    public function getAuth()
    {
        return $this->factory->createAuth();
    }
}