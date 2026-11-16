<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/** "Reset your password" email, with the shop's name. Sent from Forgot password or by an admin (Users). */
class ResetPasswordNotification extends ResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        $minutes = config('auth.passwords.users.expire');

        return (new MailMessage)
            ->salutation('Regards,'."\n".settings('business.name'))
            ->subject(settings('business.name').': reset your password')
            ->greeting("Hello {$notifiable->name},")
            ->line('We received a request to reset the password of your '.settings('business.name').' POS account.')
            ->action('Choose a new password', $this->resetUrl($notifiable))
            ->line("This link works for {$minutes} minutes and only once.")
            ->line("If you didn't ask for this, you can ignore this email — your password stays the same.");
    }
}
