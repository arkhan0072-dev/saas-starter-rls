<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TeamInvitation extends Notification
{
    public function __construct(public readonly string $token, public readonly string $company) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("You have been invited to join {$this->company}")
            ->line("You have been invited to join {$this->company}.")
            ->line("Your invitation code is: {$this->token}")
            ->line('It expires in 7 days.');
    }
}
