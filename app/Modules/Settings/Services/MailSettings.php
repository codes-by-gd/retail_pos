<?php

namespace App\Modules\Settings\Services;

use Illuminate\Support\Facades\Crypt;

/**
 * Outgoing email (SMTP) configured from Settings → Email, applied at runtime over .env.
 * The SMTP password is stored encrypted with APP_KEY. If the screen isn't used, .env MAIL_* still works.
 */
final class MailSettings
{
    // labels stay short (dropdown); the port field fills in 587 / 465 / 25 automatically
    public const ENCRYPTIONS = ['tls' => 'STARTTLS', 'ssl' => 'SSL/TLS', 'none' => 'None'];

    /** Settings → Email is switched on and complete. */
    public static function fromSettings(): bool
    {
        return (bool) settings('mail.enabled') && settings('mail.host') && settings('mail.from_address');
    }

    /** Can the app actually send email (settings screen, or a real mailer in .env)? */
    public static function ready(): bool
    {
        // "log" only writes emails to the log file (the default) — nothing reaches anyone. ("array" is the test mailer.)
        return self::fromSettings() || ! in_array(config('mail.default'), ['log', null], true);
    }

    /** Point Laravel's mailer at the saved SMTP settings (called on boot and before a test email). */
    public static function apply(): void
    {
        if (! self::fromSettings()) {
            return;
        }
        $encryption = settings('mail.encryption', 'tls');
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp',
            'mail.mailers.smtp.url' => null,
            'mail.mailers.smtp.host' => settings('mail.host'),
            'mail.mailers.smtp.port' => (int) (settings('mail.port') ?: ($encryption === 'ssl' ? 465 : 587)),
            'mail.mailers.smtp.username' => settings('mail.username'),
            'mail.mailers.smtp.password' => self::password(),
            'mail.mailers.smtp.timeout' => 15,
            'mail.from.address' => settings('mail.from_address'),
            'mail.from.name' => settings('mail.from_name') ?: settings('business.name'),
        ]);
        // drop any mailer already built with the old settings
        app()->forgetInstance('mail.manager');
        app()->forgetInstance('mailer');
    }

    public static function password(): ?string
    {
        $stored = settings('mail.password');
        if (! $stored) {
            return null;
        }
        try {
            return Crypt::decryptString($stored);
        } catch (\Throwable) {
            return null;   // APP_KEY changed — the password has to be entered again
        }
    }

    public static function encrypt(string $password): string
    {
        return Crypt::encryptString($password);
    }
}
