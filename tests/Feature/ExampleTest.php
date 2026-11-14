<?php

it('redirects the home page to the dashboard', function () {
    $this->get('/')->assertRedirect('/dashboard');
});

it('sends guests to the login page', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});
