<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Kreait\Firebase\Contract\Auth as FirebaseAuth;

class AssignPresidentRole extends Command
{
    // This is what you will type in your terminal
    protected $signature = 'firebase:set-president {uid}';
    protected $description = 'Assigns the permanent President role to a Firebase User UID';

    public function handle()
    {
        $auth = app(FirebaseAuth::class);
        $uid = $this->argument('uid');

        try {
            // This injects the permanent 'president' role directly into Google's servers
            $auth->setCustomUserClaims($uid, ['role' => 'president']);
            
            $this->info("Success! User UID: {$uid} is now permanently registered as the President.");
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to assign role: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}