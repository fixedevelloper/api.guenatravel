<?php

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});
Route::get('/test-mail', function () {
    Mail::raw('Test Mailcow', function ($message) {
        $message->to('rodrigue@cscreativ.com')
            ->subject('Test SMTP Mailcow');
    });

    return 'Mail envoyé';
});
