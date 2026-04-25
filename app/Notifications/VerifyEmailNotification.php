<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VerifyEmailNotification extends Notification
{
    use Queueable;

    protected string $signedUrl;

    public function __construct(string $signedUrl)
    {
        $this->signedUrl = $signedUrl;
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Verify your Pixxgram email address')
            ->greeting('Welcome to Pixxgram!')
            ->line('Thank you for registering. Please click the button below to verify your email address and activate your account.')
            ->action('Verify Email Address', $this->signedUrl)
            ->line('This link will expire in **60 minutes**.')
            ->line('If you did not create an account on Pixxgram, no further action is required.');
    }
}