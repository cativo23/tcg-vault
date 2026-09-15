<?php

it('sends the root somewhere useful instead of a placeholder page', function () {
    $response = $this->get('/');

    $response->assertRedirect();
});
