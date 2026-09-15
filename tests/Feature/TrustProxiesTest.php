<?php

declare(strict_types=1);

test('X-Forwarded-Proto is trusted, so the app knows a request behind Traefik was HTTPS', function () {
    $this->withHeaders(['X-Forwarded-Proto' => 'https'])->get('/up');

    expect(request()->isSecure())->toBeTrue();
});
