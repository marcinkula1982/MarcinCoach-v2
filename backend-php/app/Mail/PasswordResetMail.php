<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PasswordResetMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly string $token,
        public readonly string $resetUrl,
    ) {
    }

    public function build(): self
    {
        return $this
            ->subject('Reset hasla MarcinCoach')
            ->text('emails.password-reset');
    }
}
