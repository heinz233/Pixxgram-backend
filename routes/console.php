<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Mailtrap\Helper\ResponseHelper;
use Mailtrap\MailtrapClient;
use Mailtrap\Mime\MailtrapEmail;
use Symfony\Component\Mime\Address;

Artisan::command('send-mail', function () {
    $email = (new MailtrapEmail())
        ->from(new Address('noreply@pixxgram.co.ke', 'Pixxgram'))
        ->to(new Address('ateng.heinz@gmail.com'))
        ->subject('You are awesome!')
        ->category('Integration Test')
        ->text('Congrats for sending test email with Mailtrap!');

    $response = MailtrapClient::initSendingEmails(
        apiKey: env('MAILTRAP_API_TOKEN'),   // ← from your .env
        isSandbox: true,
        inboxId: (int) env('MAILTRAP_INBOX_ID')  // ← from your .env
    )->send($email);

    var_dump(ResponseHelper::toArray($response));

})->purpose('Send Mail');


Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
