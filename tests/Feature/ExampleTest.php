<?php

it('sends the root to the home page instead of a placeholder page', function () {
    $response = $this->get('/');

    $response->assertOk()->assertSee('Track every');
});
