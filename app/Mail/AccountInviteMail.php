<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AccountInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $name;
    public string $role;
    public string $resetLink;

    public function __construct(string $name, string $role, string $resetLink)
    {
        $this->name = $name;
        $this->role = $role;
        $this->resetLink = $resetLink;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your LG Agri-Tourism account is ready',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.account-invite',
        );
    }
}
