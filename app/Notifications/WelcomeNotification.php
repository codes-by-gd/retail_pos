<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Welcome email for a new staff member with a link to set their own password (valid 3 days). */
class WelcomeNotification extends Notification
{
    use Queueable;

    public function __construct(public string $token, public ?string $invitedBy = null) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('password.reset', ['token' => $this->token, 'email' => $notifiable->getEmailForPasswordReset()]);
        $roles = method_exists($notifiable, 'getRoleNames') ? $notifiable->getRoleNames()->implode(', ') : '';

        return (new MailMessage)
            ->salutation('Regards,'."\n".settings('business.name'))
            ->subject('Welcome to '.settings('business.name'))
            ->greeting("Hello {$notifiable->name},")
            ->line(($this->invitedBy ? "{$this->invitedBy} has" : 'An account has been').' created for you on the '.settings('business.name').' POS'.($roles ? " ({$roles})." : '.'))
            ->line('Your login email is **'.$notifiable->email.'**.')
            ->action('Set your password', $url)
            ->line('This link works for 3 days. After that, use "Forgot your password?" on the login page.');
    }
}
