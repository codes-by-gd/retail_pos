<?php

namespace App\Modules\Notifications\Notifications;

use App\Models\User;
use App\Modules\Notifications\NotificationTypes;
use App\Modules\Settings\Services\MailSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** One in-app (and optionally email) alert. Stored as JSON: type, title, body, url, level, key, branch. */
class AlertNotification extends Notification
{
    public function __construct(
        public string $alertType,
        public string $title,
        public string $body,
        public ?string $url = null,
        public string $level = 'info',        // info | warning | danger | success
        public ?string $key = null,           // de-duplication key (e.g. low_stock:variant:branch)
        public ?string $branch = null,
    ) {}

    public function via(User $notifiable): array
    {
        return settings('alerts.email_enabled') && MailSettings::ready() && $notifiable->email && $notifiable->wantsNotification($this->alertType, 'mail')
            ? ['database', 'mail']
            : ['database'];
    }

    public function toArray(User $notifiable): array
    {
        return ['type' => $this->alertType, 'title' => $this->title, 'body' => $this->body, 'url' => $this->url,
            'level' => $this->level, 'key' => $this->key, 'branch' => $this->branch];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $mail = (new MailMessage)->salutation('— '.settings('business.name').' POS')->subject(settings('business.name').': '.$this->title)
            ->line($this->body)
            ->line('Alert type: '.NotificationTypes::label($this->alertType).($this->branch ? " · {$this->branch}" : ''));

        return $this->url ? $mail->action('Open', $this->url) : $mail;
    }
}
