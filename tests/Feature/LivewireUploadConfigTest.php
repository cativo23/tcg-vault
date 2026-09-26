<?php

test('photo uploads wait on the private local disk, never the public one', function () {
    // Production's default disk is public; Livewire would otherwise keep
    // unstripped uploads in a web-served folder until they expire.
    // (Tests always use Livewire's own tmp-for-tests disk, so this checks
    // the configured value rather than FileUploadConfiguration::disk().)
    expect(config('livewire.temporary_file_upload.disk'))->toBe('local');
});
